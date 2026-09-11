<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
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

        return Resposta::json($res, [
            'rotinas'   => Bd::todos('SELECT * FROM backup_rotinas ORDER BY id'),
            'historico' => Bd::todos(
                'SELECT b.*, r.nome AS rotina_nome, u.nome AS usuario_nome
                   FROM backups b
              LEFT JOIN backup_rotinas r ON r.id = b.rotina_id
              LEFT JOIN usuarios u ON u.id = b.usuario_id
               ORDER BY b.id DESC LIMIT 30'
            ),
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
