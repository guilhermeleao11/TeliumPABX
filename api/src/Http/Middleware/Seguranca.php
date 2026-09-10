<?php
declare(strict_types=1);

namespace Telium\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Cabeçalhos de segurança e identificação da API. */
final class Seguranca implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Telium-Api', '1.0');
    }
}
