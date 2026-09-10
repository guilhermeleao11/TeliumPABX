<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Gerador\Aplicador;
use Telium\Gerador\Gerador;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/** Geração e aplicação dos .conf do Asterisk. */
final class Configuracao
{
    /** GET /api/config/estado */
    public function estado(Request $req, Response $res): Response
    {
        $ultima = Bd::um(
            'SELECT id, sucesso, criado_em, reloads FROM config_aplicacoes ORDER BY id DESC LIMIT 1'
        );

        return Resposta::json($res, [
            'pendente'        => (new Gerador())->pendente(),
            'ultima_aplicacao' => $ultima,
        ]);
    }

    /** POST /api/config/gerar */
    public function gerar(Request $req, Response $res): Response
    {
        try {
            $arquivos = (new Gerador())->gerar();
        } catch (\Throwable $e) {
            return Resposta::erro($res, $e->getMessage(), 500);
        }

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'gerar',
            'config',
            null,
            ['arquivos' => array_keys($arquivos)],
        );

        return Resposta::json($res, ['arquivos' => $arquivos]);
    }

    /** POST /api/config/aplicar — gera e recarrega o Asterisk. */
    public function aplicar(Request $req, Response $res): Response
    {
        $usuario = $req->getAttribute('usuario');

        try {
            $arquivos = (new Gerador())->gerar();
        } catch (\Throwable $e) {
            return Resposta::erro($res, $e->getMessage(), 500);
        }

        $resultado = (new Aplicador())->aplicar(
            $usuario['id'] ?? null,
            array_keys($arquivos)
        );

        Auditoria::registrar($usuario, 'aplicar', 'config', null, $resultado['etapas']);

        return Resposta::json($res, [
            'arquivos' => $arquivos,
            'aplicado' => $resultado['sucesso'],
            'etapas'   => $resultado['etapas'],
            'saida'    => $resultado['saida'],
        ], $resultado['sucesso'] ? 200 : 500);
    }
}
