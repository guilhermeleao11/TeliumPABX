<?php
declare(strict_types=1);

namespace Telium\Suporte;

use Psr\Http\Message\ResponseInterface as Response;

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
}
