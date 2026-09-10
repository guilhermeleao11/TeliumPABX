<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Gerador\Gerador;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/** GET /api/health — diagnóstico de dependências, sem exigir sessão. */
final class Saude
{
    public function __invoke(Request $req, Response $res): Response
    {
        $checagens = [
            'php'      => ['ok' => true, 'detalhe' => PHP_VERSION],
            'banco'    => $this->banco(),
            'asterisk' => $this->asterisk(),
            'janus'    => $this->janus(),
        ];

        $tudoOk = array_reduce(
            $checagens,
            static fn (bool $c, array $i): bool => $c && $i['ok'],
            true
        );

        return Resposta::json($res, [
            'servico'   => 'Telium PABX API',
            'versao'    => '1.0.0',
            'ambiente'  => Ambiente::get('APP_ENV', 'desconhecido'),
            'horario'   => date('c'),
            'saudavel'  => $tudoOk,
            'checagens' => $checagens,
        ], $tudoOk ? 200 : 503);
    }

    private function banco(): array
    {
        try {
            $versao = (string) Bd::valor('SELECT VERSION()');
            $ramais = (int) Bd::valor('SELECT COUNT(*) FROM ramais');
            $config = Bd::valor("SELECT valor FROM sistema WHERE chave = 'config_pendente'");

            return [
                'ok' => true,
                'detalhe' => "MariaDB {$versao}",
                'ramais' => $ramais,
                'config_pendente' => $config === '1',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalhe' => $e->getMessage()];
        }
    }

    private function asterisk(): array
    {
        try {
            $ami = Ami::doAmbiente();
            $ami->conectar();
            $saida = $ami->comando('core show version');
            $ami->desconectar();

            preg_match('/Asterisk\s+\S+/', $saida, $m);

            return ['ok' => true, 'detalhe' => $m[0] ?? 'AMI respondeu'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalhe' => $e->getMessage()];
        }
    }

    private function janus(): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $json = @file_get_contents('http://127.0.0.1:8088/janus/info', false, $ctx);

        if ($json === false) {
            return ['ok' => false, 'detalhe' => 'Janus não respondeu em 127.0.0.1:8088'];
        }

        $dados = json_decode($json, true);

        return [
            'ok' => isset($dados['version_string']),
            'detalhe' => 'Janus ' . ($dados['version_string'] ?? '?'),
            'plugin_sip' => isset($dados['plugins']['janus.plugin.sip']),
        ];
    }
}
