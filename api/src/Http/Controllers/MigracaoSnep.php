<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Telium\Dominio\Auditoria;
use Telium\Dominio\MigracaoSnep as Migracao;
use Telium\Dominio\SnepDump;
use Telium\Http\Recurso;
use Telium\Suporte\Resposta;

/**
 * Telium › Migração do SNEP.
 *
 * Dois passos: o envio do dump (lido uma vez e guardado só com as tabelas
 * que interessam) e a migração, que roda primeiro como prévia e depois
 * de verdade. O dump guardado tem as senhas SIP do SNEP: fica com o dono
 * da API, 0600, e some depois da importação ou em um dia.
 */
final class MigracaoSnep
{
    private const TAMANHO_MAX = 64 * 1024 * 1024;
    private const VALIDADE = 86400;

    /** @param array<string, Recurso> $recursos */
    public function __construct(private readonly array $recursos)
    {
    }

    /** POST /api/migracao-snep — envia o dump (multipart, campo "arquivo") */
    public function enviar(Request $req, Response $res): Response
    {
        $enviado = $req->getUploadedFiles()['arquivo'] ?? null;
        if (!$enviado instanceof UploadedFileInterface || $enviado->getError() !== UPLOAD_ERR_OK) {
            return Resposta::erro($res, 'Envie o arquivo do dump do SNEP no campo "arquivo".', 422);
        }
        if ($enviado->getSize() > self::TAMANHO_MAX) {
            return Resposta::erro($res, 'O dump passa de 64 MB. Gere só as tabelas da migração (veja a tela).', 422);
        }

        $dir = $this->diretorio();
        $this->limparAntigos($dir);

        $temp = tempnam($dir, 'dump-');
        $enviado->moveTo($temp);
        try {
            $dados = SnepDump::ler($temp);
        } catch (\RuntimeException $e) {
            return Resposta::erro($res, $e->getMessage(), 422);
        } finally {
            @unlink($temp);
        }

        $id = bin2hex(random_bytes(16));
        $arquivo = "{$dir}/{$id}.json";
        file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE));
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
        $simular = filter_var($corpo['simular'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $inicio = trim((string) ($corpo['fila_inicio'] ?? '8000'));
        if (preg_match('/^[0-9]{2,10}$/', $inicio) !== 1) {
            return Resposta::erro($res, 'O primeiro número de fila é só de dígitos, de 2 a 10.', 422,
                                  ['campo' => 'fila_inicio']);
        }

        $dados = json_decode((string) file_get_contents($arquivo), true) ?: [];
        $resultado = (new Migracao($this->recursos, $req))->executar($dados, ['fila_inicio' => $inicio], $simular);

        if (!$simular) {
            @unlink($arquivo);
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
            if (is_file($f) && filemtime($f) < time() - self::VALIDADE) {
                @unlink($f);
            }
        }
    }
}
