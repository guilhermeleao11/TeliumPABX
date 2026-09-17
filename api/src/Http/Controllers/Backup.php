<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\BackupRemoto;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Backup e restauração.
 *
 * A API não copia arquivo nenhum: ela registra o trabalho no banco e
 * aciona /usr/local/sbin/telium-backup, que roda como root. A regra de
 * sudo não aceita argumento, então não há nada para injetar.
 */
final class Backup
{
    /** GET /api/backup — rotinas, histórico e espaço em disco */
    public function estado(Request $req, Response $res): Response
    {
        $dir = (string) Ambiente::get('BACKUP_DIR', '/var/backup/pabx-telium');

        $historico = Bd::todos(
            'SELECT b.*, r.nome AS rotina_nome, u.nome AS usuario_nome
               FROM backups b
          LEFT JOIN backup_rotinas r ON r.id = b.rotina_id
          LEFT JOIN usuarios u ON u.id = b.usuario_id
           ORDER BY b.id DESC LIMIT 30'
        );

        // A retenção apaga os antigos: sem conferir o disco, a tela
        // ofereceria baixar um arquivo que não existe mais.
        foreach ($historico as &$b) {
            $b['baixavel'] = $b['estado'] === 'concluido' && $this->arquivoDoBackup($b) !== null;
        }
        unset($b);

        return Resposta::json($res, [
            'rotinas'   => Bd::todos('SELECT * FROM backup_rotinas ORDER BY id'),
            'historico' => $historico,
            'destino'   => $dir,
            'disco'     => $this->disco($dir),
            'executando' => (int) Bd::valor(
                "SELECT COUNT(*) FROM backups WHERE estado IN ('pendente','executando')"
            ) > 0,
        ]);
    }

    /** POST /api/backup/executar — cria o trabalho e dispara o executor */
    public function executar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $usuario = $req->getAttribute('usuario');

        $emAndamento = (int) Bd::valor(
            "SELECT COUNT(*) FROM backups WHERE estado IN ('pendente','executando')"
        );
        if ($emAndamento > 0) {
            return Resposta::erro($res, 'Já existe um backup em andamento. Aguarde ele terminar.', 409);
        }

        $retencao = max(1, min(20, (int) ($corpo['retencao'] ?? 5)));

        Bd::executar(
            'INSERT INTO backups (origem, estado, inclui_banco, inclui_config, inclui_audios,
                                  inclui_gravacoes, retencao, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'manual', 'pendente',
                $this->flag($corpo, 'inclui_banco', true),
                $this->flag($corpo, 'inclui_config', true),
                $this->flag($corpo, 'inclui_audios', true),
                $this->flag($corpo, 'inclui_gravacoes', false),
                $retencao,
                $usuario['id'] ?? null,
            ]
        );
        $id = (int) Bd::conexao()->lastInsertId();

        Auditoria::registrar($usuario, 'backup', 'admin.backup', (string) $id, [
            'gravacoes' => $this->flag($corpo, 'inclui_gravacoes', false) === 1,
        ]);

        $disparo = $this->dispararExecutor();

        return Resposta::json($res, [
            'id' => $id,
            'disparado' => $disparo['ok'],
            'detalhe' => $disparo['detalhe'],
        ], 202);
    }

    /** GET /api/backup/destino — para onde o backup vai depois de pronto. */
    public function destino(Request $req, Response $res): Response
    {
        $d = BackupRemoto::destino();
        // A senha nunca volta para a tela; o campo em branco quer dizer
        // "mantenha a que está", igual ao resto do console.
        $d['senha'] = null;
        $d['tem_senha'] = trim((string) (BackupRemoto::destino()['senha'] ?? '')) !== '';
        $d['curl'] = function_exists('curl_init');

        return Resposta::json($res, $d);
    }

    /** PUT /api/backup/destino */
    public function salvarDestino(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        $atual = BackupRemoto::destino();

        $tipo = in_array($c['tipo'] ?? '', ['ftp', 'ftps', 'sftp'], true) ? $c['tipo'] : 'ftps';
        $host = trim((string) ($c['host'] ?? ''));
        $porta = (int) ($c['porta'] ?? 0);
        if ($porta < 1 || $porta > 65535) {
            $porta = $tipo === 'sftp' ? 22 : 21;
        }

        // Senha em branco mantém a que está: a tela nunca a recebe de
        // volta, então enviá-la vazia é "não mexi", e não "apague".
        $senha = array_key_exists('senha', $c) && $c['senha'] !== null && $c['senha'] !== ''
            ? (string) $c['senha']
            : (string) ($atual['senha'] ?? '');

        Bd::executar(
            "UPDATE backup_destino_remoto
                SET ativo = ?, tipo = ?, host = ?, porta = ?, usuario = ?, senha = ?,
                    caminho = ?, passivo = ?, aceitar_cert_invalido = ?
              WHERE id = 1",
            [
                (int) !empty($c['ativo']),
                $tipo,
                $host,
                $porta,
                trim((string) ($c['usuario'] ?? '')),
                $senha,
                trim((string) ($c['caminho'] ?? '/')) ?: '/',
                (int) !empty($c['passivo']),
                (int) !empty($c['aceitar_cert_invalido']),
            ]
        );

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'admin.backup', 'destino remoto', [
            'tipo' => $tipo, 'host' => $host, 'ativo' => !empty($c['ativo']),
        ]);

        return $this->destino($req, $res);
    }

    /** POST /api/backup/destino/testar — grava um arquivinho e apaga. */
    public function testarDestino(Request $req, Response $res): Response
    {
        $r = BackupRemoto::testar(BackupRemoto::destino());

        return Resposta::json($res, ['ok' => $r['ok'], 'detalhe' => $r['detalhe']], $r['ok'] ? 200 : 502);
    }

    /** POST /api/backup/{id}/enviar — manda um backup específico agora. */
    public function enviar(Request $req, Response $res, array $args): Response
    {
        $b = Bd::um('SELECT * FROM backups WHERE id = ?', [(int) $args['id']]);
        if ($b === null) {
            return Resposta::erro($res, 'Backup não encontrado.', 404);
        }

        $caminho = $this->arquivoDoBackup($b);
        if ($caminho === null) {
            return Resposta::erro($res, 'O arquivo deste backup não está mais no servidor.', 404);
        }

        $d = BackupRemoto::destino();
        if (!BackupRemoto::utilizavel($d)) {
            return Resposta::erro(
                $res,
                'Nenhum destino remoto configurado e ligado. Preencha o destino nesta mesma tela.',
                422
            );
        }

        $r = BackupRemoto::enviar($caminho, $d);
        BackupRemoto::registrar($r['ok'], $r['detalhe']);

        Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'admin.backup', basename($caminho), [
            'destino' => $d['host'], 'ok' => $r['ok'],
        ]);

        return Resposta::json($res, $r, $r['ok'] ? 200 : 502);
    }

    /**
     * GET /api/backup/{id}/baixar — leva o arquivo para fora do servidor.
     *
     * Backup que só existe dentro da máquina que ele deveria salvar não
     * é backup: perdido o servidor, perde-se junto. Faltava qualquer
     * caminho para tirar o arquivo de lá pelo console — só sobrava o
     * scp, que quem administra a central nem sempre tem.
     */
    public function baixar(Request $req, Response $res, array $args): Response
    {
        $b = Bd::um('SELECT * FROM backups WHERE id = ?', [(int) $args['id']]);
        if ($b === null) {
            return Resposta::erro($res, 'Backup não encontrado.', 404);
        }
        if ($b['estado'] !== 'concluido') {
            return Resposta::erro(
                $res,
                'Este backup não terminou' . ($b['estado'] === 'falha' ? ' — ele falhou.' : ' ainda.'),
                409
            );
        }

        $caminho = $this->arquivoDoBackup($b);
        if ($caminho === null) {
            return Resposta::erro(
                $res,
                'O arquivo deste backup não está mais no servidor. '
                . 'A retenção pode tê-lo apagado para abrir espaço.',
                404
            );
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'admin.backup', basename($caminho), [
            'tamanho' => filesize($caminho),
        ]);

        return Resposta::arquivo($req, $res, $caminho, 'application/gzip', true);
    }

    /**
     * O arquivo de um backup, conferido contra o diretório de destino.
     *
     * O nome vem do banco, e o banco é alimentado por um script: mesmo
     * assim o caminho é resolvido e comparado com a base, porque "..'
     * num nome de arquivo é a diferença entre baixar um backup e baixar
     * /etc/shadow.
     */
    private function arquivoDoBackup(array $b): ?string
    {
        $nome = trim((string) ($b['arquivo'] ?? ''));
        if ($nome === '' || str_contains($nome, "\0")) {
            return null;
        }

        $base = realpath((string) Ambiente::get('BACKUP_DIR', '/var/backup/pabx-telium'));
        // O script grava ora o caminho completo, ora só o nome.
        $alvo = realpath(str_starts_with($nome, '/') ? $nome : $base . '/' . ltrim($nome, '/'));

        if ($base === false || $alvo === false || !is_file($alvo)) {
            return null;
        }

        return str_starts_with($alvo, $base . DIRECTORY_SEPARATOR) ? $alvo : null;
    }

    /** POST /api/backup/{id}/restaurar */
    public function restaurar(Request $req, Response $res, array $args): Response
    {
        $backup = Bd::um('SELECT * FROM backups WHERE id = ?', [$args['id']]);

        if ($backup === null || $backup['arquivo'] === null) {
            return Resposta::erro($res, 'Backup não encontrado ou já removido pela retenção', 404);
        }
        if ($backup['estado'] !== 'concluido') {
            return Resposta::erro($res, 'Só é possível restaurar um backup concluído', 409);
        }

        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('restaurar_backup_id', ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
            [(string) $backup['id']]
        );

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'restaurar',
            'admin.backup',
            (string) $backup['arquivo']
        );

        $disparo = $this->dispararExecutor();

        return Resposta::json($res, [
            'restaurando' => $disparo['ok'],
            'detalhe' => $disparo['detalhe'],
            'aviso' => 'O Asterisk é parado durante a restauração e volta em seguida.',
        ], 202);
    }

    /** DELETE /api/backup/{id} — apaga o registro e o arquivo */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $backup = Bd::um('SELECT * FROM backups WHERE id = ?', [$args['id']]);
        if ($backup === null) {
            return Resposta::erro($res, 'Backup não encontrado', 404);
        }

        if ($backup['arquivo']) {
            $caminho = rtrim((string) Ambiente::get('BACKUP_DIR', '/var/backup/pabx-telium'), '/')
                     . '/' . basename((string) $backup['arquivo']);
            // O arquivo pertence ao root; sem privilégio, apenas soltamos o registro.
            @unlink($caminho);
        }

        Bd::executar('DELETE FROM backups WHERE id = ?', [$backup['id']]);
        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'admin.backup',
                             (string) ($backup['arquivo'] ?? $backup['id']));

        return Resposta::json($res, ['removido' => true]);
    }

    // ------------------------------------------------------------------
    private function flag(array $corpo, string $chave, bool $padrao): int
    {
        $v = $corpo[$chave] ?? $padrao;
        return (is_bool($v) ? $v : in_array($v, [1, '1', 'true', true], true)) ? 1 : 0;
    }

    /** @return array{ok:bool, detalhe:string} */
    private function dispararExecutor(): array
    {
        $saida = [];
        $rc = 0;
        exec('sudo -n /usr/local/sbin/telium-backup > /dev/null 2>&1 &', $saida, $rc);

        if ($rc !== 0) {
            return [
                'ok' => false,
                'detalhe' => 'Não foi possível acionar o executor. O trabalho ficou pendente e '
                           . 'o temporizador do sistema vai pegá-lo em até um minuto.',
            ];
        }

        return ['ok' => true, 'detalhe' => 'Backup iniciado em segundo plano.'];
    }

    private function disco(string $dir): array
    {
        $total = (float) @disk_total_space($dir) ?: 0.0;
        $livre = (float) @disk_free_space($dir) ?: 0.0;

        return [
            'total_gb' => round($total / 1073741824, 1),
            'livre_gb' => round($livre / 1073741824, 1),
            'pct_uso'  => $total > 0 ? (int) round(($total - $livre) / $total * 100) : 0,
        ];
    }
}
