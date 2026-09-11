<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Quem está numa sala de conferência agora.
 *
 * O cadastro da sala é o CRUD genérico; isto aqui é a leitura do estado
 * ao vivo, que só o Asterisk sabe.
 */
final class Conferencias
{
    /** GET /api/conferencias/{id}/situacao */
    public function situacao(Request $req, Response $res, array $args): Response
    {
        $sala = Bd::um('SELECT * FROM conferencias WHERE id = ?', [$args['id']]);
        if ($sala === null) {
            return Resposta::erro($res, 'Sala não encontrada', 404);
        }

        $saida = Ami::tentarComando("confbridge list {$sala['numero']}");

        if ($saida === null) {
            return Resposta::json($res, [
                'disponivel' => false,
                'participantes' => [],
                'detalhe' => 'Sem comunicação com o Asterisk agora.',
            ]);
        }

        // Sala sem ninguém: o Asterisk nem cria a ponte.
        if (stripos($saida, 'no conference bridge named') !== false) {
            return Resposta::json($res, [
                'disponivel' => true,
                'numero' => $sala['numero'],
                'participantes' => [],
                'saida' => trim($this->semCor($saida)),
            ]);
        }

        return Resposta::json($res, [
            'disponivel' => true,
            'numero' => $sala['numero'],
            'participantes' => $this->lerParticipantes($saida),
            'saida' => trim($this->semCor($saida)),
        ]);
    }

    /**
     * Lê a tabela de "confbridge list <sala>".
     *
     *   Channel                   Flags  User Profile  Bridge Profile  Menu  CallerID
     *   Local/x@y-00000002;2      AMm    sala-5000-…   sala-5000       …     1002
     *
     * O nome do canal estoura a coluna com frequência, então a leitura é
     * por espaços e não por posição. As flags podem não existir, daí o
     * grupo opcional — e elas nunca se confundem com um nome de perfil,
     * que sempre tem hífen ou dígito.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lerParticipantes(string $saida): array
    {
        $pessoas = [];

        foreach (explode("\n", $this->semCor($saida)) as $linha) {
            $linha = rtrim($linha);
            if ($linha === '' || str_starts_with($linha, 'Channel') || str_starts_with($linha, '===')) {
                continue;
            }

            if (preg_match('/^(\S+)\s+(?:([AMWEmw]+)\s+)?(\S+)\s+(\S+)\s+(\S+)\s*(.*)$/', $linha, $m) !== 1) {
                continue;
            }

            $flags = $m[2] ?? '';
            $pessoas[] = [
                'canal'   => $m[1],
                'perfil'  => $m[3],
                'menu'    => $m[5],
                'nome'    => trim($m[6]) ?: null,
                'admin'   => str_contains($flags, 'A'),
                'marcado' => str_contains($flags, 'M'),
                'mudo'    => str_contains($flags, 'm'),
                'esperando' => str_contains($flags, 'w'),
            ];
        }

        return $pessoas;
    }

    private function semCor(string $texto): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $texto) ?? $texto;
    }
}
