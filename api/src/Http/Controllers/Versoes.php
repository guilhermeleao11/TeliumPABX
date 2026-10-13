<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Suporte\Changelog;
use Telium\Suporte\Resposta;
use Telium\Suporte\Versao;

/**
 * GET /api/sistema/versoes — a versão desta central e o histórico.
 *
 * Quem abre um chamado precisa dizer o que está rodando, e quem atualiza
 * precisa saber o que mudou: as duas coisas na mesma tela.
 */
final class Versoes
{
    public function __invoke(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'versao'   => Versao::NUMERO,
            'commit'   => Versao::commit(),
            'exibicao' => Versao::exibicao(),
        ] + Changelog::ler());
    }
}
