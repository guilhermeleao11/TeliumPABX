<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Telium\Dominio\Auditoria;
use Telium\Dominio\MigracaoSnep as Migracao;
use Telium\Dominio\Permissoes;
use Telium\Dominio\SnepDump;
use Telium\Dominio\SnepPacote;
use Telium\Http\Recurso;
use Telium\Suporte\Resposta;

/**
 * Telium › Migração do SNEP.
 *
 * Dois passos: o envio (o pacote que o comando da tela gera no SNEP, com
 * banco, áudios e .conf, ou só o dump do banco), lido uma vez e guardado
 * só com o que interessa; e a migração, que roda primeiro como prévia e
 * depois de verdade. O que fica guardado tem as senhas SIP do SNEP: é do
 * dono da API, 0600, e some depois da importação ou em um dia.
 */
final class MigracaoSnep
{
    private const TAMANHO_MAX = 64 * 1024 * 1024;

    /**
     * Os módulos em que a migração cria coisas. O cadastro chamado daqui
     * não passa pela rota de cada tela, que é quem confere a permissão:
     * a conferência é esta.
     */
    private const MODULOS = [
        'conn.troncos' => 'Troncos', 'conn.ramais' => 'Ramais', 'apps.filas' => 'Filas',
        'cc.agentes' => 'Agentes do Call Center', 'conn.rotassaida' => 'Rotas de saída',
        'conn.rotasentrada' => 'Rotas de entrada', 'apps.ura' => 'URA', 'apps.anuncios' => 'Anúncios',
        'admin.destinos' => 'Destinos personalizados', 'apps.grupohorario' => 'Grupos de horário',
        'apps.condicoes' => 'Condições horárias',
    ];
    private const VALIDADE = 86400;

    /** @param array<string, Recurso> $recursos */
    public function __construct(private readonly array $recursos)
    {
    }

    /** POST /api/migracao-snep — envia o dump (multipart, campo "arquivo") */
    public function enviar(Request $req, Response $res): Response
    {
        $enviado = $req->getUploadedFiles()['arquivo'] ?? null;
        // Acima do post_max_size o PHP descarta o envio inteiro, calado: o
        // pedido chega sem arquivo nenhum.
        $tamanho = (int) ($req->getServerParams()['CONTENT_LENGTH'] ?? 0);
        if ($enviado === null && $tamanho > 0) {
            return Resposta::erro($res, sprintf('O arquivo (%.0f MB) passa do limite de envio deste servidor (%s). '
                . 'Gere só as tabelas da migração (veja a tela).', $tamanho / 1048576, ini_get('post_max_size')), 422);
        }
        if (!$enviado instanceof UploadedFileInterface || $enviado->getError() !== UPLOAD_ERR_OK) {
            return Resposta::erro($res, 'Envie o arquivo do dump do SNEP no campo "arquivo".', 422);
        }
        if ($enviado->getSize() > self::TAMANHO_MAX) {
            return Resposta::erro($res, 'O arquivo passa de 64 MB. Gere o pacote pelo comando da tela (sem CDR).', 422);
        }

        $dir = $this->diretorio();
        $this->limparAntigos($dir);

        $id = bin2hex(random_bytes(16));
        $trabalho = "{$dir}/{$id}";
        @mkdir($trabalho, 0700, true);
        $temp = tempnam($dir, 'envio-');
        $enviado->moveTo($temp);
        $pacote = null;
        try {
            if (SnepPacote::ehPacote((string) $enviado->getClientFilename())) {
                $pacote = SnepPacote::extrair($temp, $trabalho);
                $dados = SnepDump::ler((string) $pacote['sql']);
                @unlink((string) $pacote['sql']);
            } else {
                $dados = SnepDump::ler($temp);
            }
        } catch (\RuntimeException $e) {
            self::apagar($trabalho);

            return Resposta::erro($res, $e->getMessage(), 422);
        } finally {
            @unlink($temp);
        }
        if ($pacote === null) {
            self::apagar($trabalho);
        }

        $arquivo = "{$dir}/{$id}.json";
        file_put_contents($arquivo, json_encode($dados + ['_pacote' => $pacote], JSON_UNESCAPED_UNICODE));
        @chmod($arquivo, 0600);

        $ramais = count(array_filter($dados['peers'], static fn ($p) => ($p['peer_type'] ?? 'R') === 'R'));

        return Resposta::json($res, [
            'id' => $id,
            'nome' => (string) $enviado->getClientFilename(),
            'contagem' => [
                'ramais' => $ramais,
                'troncos' => count($dados['trunks']),
                'filas' => count($dados['queues']),
                'regras' => count($dados['regras_negocio']),
                'agentes' => count($dados['agtfila']),
                'audios' => $pacote === null ? null : count($pacote['sons']),
                'musicas' => $pacote === null ? null : count($pacote['moh']),
            ],
        ], 201);
    }

    /** POST /api/migracao-snep/{id} — {simular, fila_inicio} */
    public function migrar(Request $req, Response $res, array $args): Response
    {
        $id = (string) ($args['id'] ?? '');
        $arquivo = "{$this->diretorio()}/{$id}.json";
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1 || !is_file($arquivo)) {
            return Resposta::erro($res, 'O dump enviado não está mais no servidor. Envie de novo.', 404);
        }

        $corpo = (array) $req->getParsedBody();
        // Na dúvida, prévia: só "false" de verdade grava.
        $simular = filter_var($corpo['simular'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        // A importação converte áudios e cria centenas de itens: não pode
        // parar no meio porque o navegador desistiu de esperar.
        @set_time_limit(900);
        ignore_user_abort(true);
        $inicio = trim((string) ($corpo['fila_inicio'] ?? '8000'));
        if (preg_match('/^[0-9]{2,10}$/', $inicio) !== 1) {
            return Resposta::erro($res, 'O primeiro número de fila é só de dígitos, de 2 a 10.', 422,
                                  ['campo' => 'fila_inicio']);
        }

        $allow = (array) $req->getAttribute('allow', []);
        $falta = array_values(array_filter(self::MODULOS,
            static fn (string $modulo) => !Permissoes::podeModulo($allow, $modulo), ARRAY_FILTER_USE_KEY));
        if ($falta !== [] || !Permissoes::podeAcao((array) $req->getAttribute('caps', []), 'criar')) {
            return Resposta::erro($res, 'Para migrar, o seu perfil precisa poder criar em: '
                . ($falta !== [] ? implode(', ', $falta) : 'todos os módulos da migração') . '.', 403);
        }

        $dados = json_decode((string) file_get_contents($arquivo), true) ?: [];
        $pacote = $dados['_pacote'] ?? null;
        unset($dados['_pacote']);
        // O que a pessoa desmarcou na prévia: "Grupo|item".
        $ignorar = array_values(array_filter(array_map('strval', (array) ($corpo['ignorar'] ?? [])),
            static fn (string $c) => $c !== '' && strlen($c) <= 300));
        $resultado = (new Migracao($this->recursos, $req))->executar(
            $dados, ['fila_inicio' => $inicio, 'pacote' => $pacote, 'ignorar' => array_slice($ignorar, 0, 5000)], $simular
        );

        // Cancelada pela trava (deixaria a central com erro): o dump fica,
        // para desmarcar o item e importar de novo.
        if (!$simular && ($resultado['cancelada'] ?? null) === null) {
            @unlink($arquivo);
            self::apagar("{$this->diretorio()}/{$id}");
            Auditoria::registrar($req->getAttribute('usuario'), 'importar', 'telium.migracao',
                                 'SNEP', $resultado['resumo'], $req->getServerParams()['REMOTE_ADDR'] ?? null);
        }

        return Resposta::json($res, $resultado);
    }

    private function diretorio(): string
    {
        $dir = dirname(__DIR__, 3) . '/var/migracao-snep';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private function limparAntigos(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $f) {
            if (filemtime($f) < time() - self::VALIDADE) {
                is_dir($f) ? self::apagar($f) : @unlink($f);
            }
        }
    }

    /** Apaga a pasta de trabalho de um envio, com o que tiver dentro. */
    private static function apagar(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($itens as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($pasta);
    }
}
