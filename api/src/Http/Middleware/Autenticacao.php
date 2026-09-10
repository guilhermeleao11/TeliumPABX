<?php
declare(strict_types=1);

namespace Telium\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Sessao;
use Telium\Suporte\Resposta;

/** Exige sessão válida e injeta o usuário no request. */
final class Autenticacao implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $token = $this->token($request);

        if ($token === null) {
            return Resposta::erro(new SlimResponse(), 'Não autenticado', 401);
        }

        $usuario = Sessao::usuarioDoToken($token);
        if ($usuario === null) {
            return Resposta::erro(new SlimResponse(), 'Sessão expirada ou inválida', 401);
        }

        $permissoes = Permissoes::doPerfil((int) $usuario['perfil_id']);

        $request = $request
            ->withAttribute('usuario', $usuario)
            ->withAttribute('token', $token)
            ->withAttribute('allow', $permissoes['allow'])
            ->withAttribute('caps', $permissoes['caps']);

        return $handler->handle($request);
    }

    private function token(Request $request): ?string
    {
        $cabecalho = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $cabecalho, $m) === 1) {
            return $m[1];
        }

        $cookies = $request->getCookieParams();
        return $cookies['telium_sessao'] ?? null;
    }
}
