<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Gerador\Gerador;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Dominio\Sessao;
use Telium\Suporte\Esquema;
use Telium\Suporte\Versao;
use Telium\Suporte\Resposta;

/**
 * GET /api/health — diagnóstico de dependências.
 *
 * A rota não exige sessão porque precisa responder quando o console
 * inteiro está fora do ar, e é ela que o monitoramento consulta. Mas o
 * detalhe só vai para quem está autenticado: a versão exata do PHP, a
 * do MariaDB, o erro do AMI e a quantidade de ramais juntos são uma
 * ficha técnica pronta para quem estiver escolhendo um exploit.
 */
final class Saude
{
    public function __invoke(Request $req, Response $res): Response
    {
        $checagens = [
            'php'      => ['ok' => true, 'detalhe' => PHP_VERSION],
            'banco'    => $this->banco(),
            'esquema'  => $this->esquema(),
            'asterisk' => $this->asterisk(),
        ];

        $tudoOk = array_reduce(
            $checagens,
            static fn (bool $c, array $i): bool => $c && $i['ok'],
            true
        );

        if (!$this->autenticado($req)) {
            return Resposta::json($res, [
                'servico'  => Versao::NOME . ' — API',
                'versao'   => Versao::NUMERO,
                'saudavel' => $tudoOk,
            ], $tudoOk ? 200 : 503);
        }

        return Resposta::json($res, [
            'servico'   => Versao::NOME . ' — API',
            'versao'    => Versao::NUMERO,
            'ambiente'  => Ambiente::get('APP_ENV', 'desconhecido'),
            'horario'   => date('c'),
            'saudavel'  => $tudoOk,
            'checagens' => $checagens,
        ], $tudoOk ? 200 : 503);
    }

    /** Tem sessão válida? A rota não exige, mas o detalhe depende disso. */
    private function autenticado(Request $req): bool
    {
        $cabecalho = $req->getHeaderLine('Authorization');
        $token = preg_match('/^Bearer\s+(\S+)$/i', $cabecalho, $m) === 1
            ? $m[1]
            : ($req->getCookieParams()['telium_sessao'] ?? '');

        return $token !== '' && Sessao::usuarioDoToken((string) $token) !== null;
    }

    /**
     * O banco tem o que esta versão do código precisa?
     *
     * Sem esta checagem, um "git pull" sem migração só dá sinal quando
     * alguém clica em aplicar e leva um erro de SQL na cara.
     */
    private function esquema(): array
    {
        $r = Esquema::conferir();

        return ['ok' => $r['ok'], 'detalhe' => $r['mensagem']];
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
            $saida = Ami::compartilhada()->comando('core show version');

            preg_match('/Asterisk\s+\S+/', $saida, $m);

            return ['ok' => true, 'detalhe' => $m[0] ?? 'AMI respondeu'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detalhe' => $e->getMessage()];
        }
    }

}
