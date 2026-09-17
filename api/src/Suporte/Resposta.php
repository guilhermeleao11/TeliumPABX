<?php
declare(strict_types=1);

namespace Telium\Suporte;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class Resposta
{
    public static function json(Response $r, mixed $dados, int $status = 200): Response
    {
        $r->getBody()->write(json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        ));
        return $r->withHeader('Content-Type', 'application/json; charset=utf-8')
                 ->withStatus($status);
    }

    public static function erro(Response $r, string $mensagem, int $status = 400, array $extra = []): Response
    {
        return self::json($r, ['erro' => $mensagem] + $extra, $status);
    }

    /**
     * Entrega um arquivo de mídia, em fluxo e com suporte a Range.
     *
     * Antes cada rota fazia file_get_contents e escrevia o conteúdo
     * inteiro no corpo. Isso funciona no teste, com gravação de dois
     * segundos, e quebra no cliente: uma chamada de uma hora em wav de
     * 8 kHz tem uns 57 MB, e o PHP precisa dela DUAS vezes na memória —
     * a string lida e a cópia no corpo. Passando do memory_limit, a
     * resposta vira erro 500 e a tela só diz que não deu para ouvir.
     *
     * O Range é a outra metade. O elemento <audio> pede pedaços para
     * poder arrastar a barra, e o Safari chega pedindo "bytes=0-1"
     * antes de tocar qualquer coisa: servidor que ignora Range faz o
     * player mostrar a duração como infinita, não deixa avançar e, no
     * Safari, não toca.
     */
    public static function arquivo(
        Request $req,
        Response $r,
        string $caminho,
        string $tipo,
        bool $baixar = false,
        ?string $nome = null
    ): Response {
        $nome ??= basename($caminho);
        $tamanho = (int) filesize($caminho);

        $inicio = 0;
        $fim = $tamanho - 1;
        $parcial = false;

        $faixa = trim($req->getHeaderLine('Range'));
        if ($faixa !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $faixa, $m) === 1) {
            $pedidoInicio = $m[1] === '' ? null : (int) $m[1];
            $pedidoFim = $m[2] === '' ? null : (int) $m[2];

            if ($pedidoInicio === null && $pedidoFim !== null) {
                // "bytes=-500": os últimos 500 bytes.
                $inicio = max(0, $tamanho - $pedidoFim);
            } elseif ($pedidoInicio !== null) {
                $inicio = $pedidoInicio;
                $fim = $pedidoFim !== null ? min($pedidoFim, $tamanho - 1) : $tamanho - 1;
            }

            if ($inicio > $fim || $inicio >= $tamanho) {
                return $r->withStatus(416)
                         ->withHeader('Content-Range', "bytes */{$tamanho}");
            }
            $parcial = true;
        }

        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            return self::erro($r, 'Não foi possível ler o arquivo no servidor.', 500);
        }
        if ($inicio > 0) {
            fseek($arquivo, $inicio);
        }

        $resposta = $r
            ->withBody(new Stream($arquivo))
            ->withHeader('Content-Type', $tipo)
            ->withHeader('Content-Length', (string) ($fim - $inicio + 1))
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition',
                ($baixar ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $nome) . '"');

        return $parcial
            ? $resposta->withStatus(206)->withHeader('Content-Range', "bytes {$inicio}-{$fim}/{$tamanho}")
            : $resposta;
    }
}
