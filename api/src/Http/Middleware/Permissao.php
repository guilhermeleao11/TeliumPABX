<?php
declare(strict_types=1);

namespace Telium\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Telium\Dominio\Permissoes;
use Telium\Suporte\Resposta;

/**
 * Revalida no servidor o que o front já escondeu na interface.
 * O menu filtrado é conveniência de UI; a decisão real é esta aqui.
 */
final class Permissao implements MiddlewareInterface
{
    /**
     * @param list<string> $tambem outros módulos que também abrem esta porta
     *
     * O terceiro parâmetro existe por causa do seletor de destino: a tela
     * de URA precisa listar ramais para apontar uma tecla, e exigir dela
     * o módulo "Ramais" deixava 19 telas quebradas para todo perfil que
     * não fosse o administrador. Escolher não é administrar — vale só
     * para ler a lista; gravar e abrir o cadastro seguem com o dono.
     */
    public function __construct(
        private readonly string $modulo,
        private readonly ?string $acao = null,
        private readonly array $tambem = [],
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $allow = $request->getAttribute('allow', []);
        $caps  = $request->getAttribute('caps', []);

        $pode = Permissoes::podeModulo($allow, $this->modulo);
        foreach ($this->tambem as $outro) {
            $pode = $pode || Permissoes::podeModulo($allow, $outro);
        }

        if (!$pode) {
            return Resposta::erro(
                new SlimResponse(),
                "Seu perfil não tem acesso ao módulo {$this->modulo}",
                403,
                ['modulo' => $this->modulo]
            );
        }

        if ($this->acao !== null && !Permissoes::podeAcao($caps, $this->acao)) {
            return Resposta::erro(
                new SlimResponse(),
                "Seu perfil não pode executar a ação: {$this->acao}",
                403,
                ['acao' => $this->acao]
            );
        }

        return $handler->handle($request);
    }
}
