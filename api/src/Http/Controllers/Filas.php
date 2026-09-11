<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Filas: agentes, situação ao vivo e resultado da pesquisa.
 *
 * O cadastro da fila em si é o CRUD genérico; o que mora aqui é o que
 * não cabe numa linha de tabela — a lista de agentes, o que o AMI diz
 * neste instante e a leitura das notas.
 */
final class Filas
{
    /** GET /api/filas/{id}/agentes */
    public function agentes(Request $req, Response $res, array $args): Response
    {
        $fila = Bd::um('SELECT * FROM filas WHERE id = ?', [$args['id']]);
        if ($fila === null) {
            return Resposta::erro($res, 'Fila não encontrada', 404);
        }

        return Resposta::json($res, [
            'fila' => ['id' => (int) $fila['id'], 'numero' => $fila['numero'],
                       'nome' => $fila['nome'], 'callcenter' => (int) $fila['callcenter']],
            'agentes' => Bd::todos(
                'SELECT a.ramal_id, a.penalidade, a.tipo, a.origem,
                        r.numero, r.nome, r.setor
                   FROM fila_agentes a
                   JOIN ramais r ON r.id = a.ramal_id
                  WHERE a.fila_id = ?
               ORDER BY a.penalidade, r.numero',
                [$fila['id']]
            ),
            'disponiveis' => Bd::todos(
                'SELECT id, numero, nome, setor FROM ramais
                  WHERE ativo = 1 AND id NOT IN (SELECT ramal_id FROM fila_agentes WHERE fila_id = ?)
               ORDER BY numero',
                [$fila['id']]
            ),
        ]);
    }

    /** PUT /api/filas/{id}/agentes — troca a lista inteira */
    public function salvarAgentes(Request $req, Response $res, array $args): Response
    {
        $fila = Bd::um('SELECT * FROM filas WHERE id = ?', [$args['id']]);
        if ($fila === null) {
            return Resposta::erro($res, 'Fila não encontrada', 404);
        }
        if ((int) $fila['callcenter'] === 1) {
            return Resposta::erro(
                $res,
                'Esta é uma fila de call center: os agentes dela são atribuídos pelo módulo '
                . 'de call center, na criação do agente. Desmarque "fila de call center" '
                . 'para montar a lista aqui.',
                409,
                ['campo' => 'callcenter']
            );
        }

        $corpo = (array) $req->getParsedBody();
        $lista = (array) ($corpo['agentes'] ?? []);

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            // Os que vieram do call center não são mexidos aqui.
            Bd::executar("DELETE FROM fila_agentes WHERE fila_id = ? AND origem = 'manual'",
                         [$fila['id']]);

            foreach ($lista as $a) {
                $ramalId = (int) ($a['ramal_id'] ?? 0);
                if ($ramalId === 0) {
                    continue;
                }

                Bd::executar(
                    'INSERT INTO fila_agentes (fila_id, ramal_id, penalidade, tipo, origem)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE penalidade = VALUES(penalidade), tipo = VALUES(tipo)',
                    [
                        $fila['id'], $ramalId,
                        max(0, min(255, (int) ($a['penalidade'] ?? 0))),
                        in_array($a['tipo'] ?? '', ['estatico', 'dinamico'], true) ? $a['tipo'] : 'estatico',
                        'manual',
                    ]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            return Resposta::erro($res, 'Não foi possível salvar os agentes: ' . $e->getMessage(), 400);
        }

        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'apps.filas',
                             (string) $fila['numero'], ['agentes' => count($lista)]);

        return Resposta::json($res, ['ok' => true, 'agentes' => count($lista)]);
    }

    /** GET /api/filas/{id}/situacao — o que o AMI diz agora */
    public function situacao(Request $req, Response $res, array $args): Response
    {
        $fila = Bd::um('SELECT * FROM filas WHERE id = ?', [$args['id']]);
        if ($fila === null) {
            return Resposta::erro($res, 'Fila não encontrada', 404);
        }

        $saida = Ami::tentarComando("queue show {$fila['numero']}");
        if ($saida === null) {
            return Resposta::json($res, [
                'disponivel' => false,
                'detalhe' => 'Sem comunicação com o Asterisk agora.',
            ]);
        }

        return Resposta::json($res, [
            'disponivel' => true,
            'numero' => $fila['numero'],
            'saida' => trim($saida),
            'membros' => $this->lerMembros($saida),
            'esperando' => $this->lerEsperando($saida),
        ]);
    }

    /** GET /api/pesquisas/resultados */
    public function resultados(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $de = ($p['de'] ?? '') !== '' ? $p['de'] : date('Y-m-d', strtotime('-30 days'));
        $ate = ($p['ate'] ?? '') !== '' ? $p['ate'] : date('Y-m-d');

        $where = ['r.criado_em >= ?', 'r.criado_em < ?'];
        $args = [$de . ' 00:00:00', $ate . ' 23:59:59'];

        if (($p['fila'] ?? '') !== '') {
            $where[] = 'r.fila = ?';
            $args[] = $p['fila'];
        }
        $filtro = ' WHERE ' . implode(' AND ', $where);

        $resumo = Bd::um(
            "SELECT COUNT(*) AS total,
                    SUM(r.nota IS NOT NULL) AS responderam,
                    ROUND(AVG(r.nota), 2) AS media
               FROM pesquisa_respostas r {$filtro}",
            $args
        ) ?? [];

        return Resposta::json($res, [
            'periodo' => ['de' => $de, 'ate' => $ate],
            'resumo' => [
                'total' => (int) ($resumo['total'] ?? 0),
                'responderam' => (int) ($resumo['responderam'] ?? 0),
                'media' => $resumo['media'] !== null ? (float) $resumo['media'] : null,
            ],
            'por_nota' => Bd::todos(
                "SELECT r.nota, COUNT(*) AS quantidade
                   FROM pesquisa_respostas r {$filtro} AND r.nota IS NOT NULL
               GROUP BY r.nota ORDER BY r.nota",
                $args
            ),
            'por_fila' => Bd::todos(
                "SELECT r.fila, f.nome, COUNT(*) AS total,
                        SUM(r.nota IS NOT NULL) AS responderam,
                        ROUND(AVG(r.nota), 2) AS media
                   FROM pesquisa_respostas r
              LEFT JOIN filas f ON f.numero = r.fila
                  {$filtro}
               GROUP BY r.fila, f.nome ORDER BY media DESC",
                $args
            ),
            'por_agente' => Bd::todos(
                "SELECT r.agente, COUNT(*) AS total,
                        SUM(r.nota IS NOT NULL) AS responderam,
                        ROUND(AVG(r.nota), 2) AS media
                   FROM pesquisa_respostas r {$filtro} AND r.agente IS NOT NULL
               GROUP BY r.agente ORDER BY media DESC LIMIT 30",
                $args
            ),
        ]);
    }

    // ------------------------------------------------------------------
    /**
     * Lê a saída de "queue show <fila>".
     *
     * O formato é o do CLI, com cor e tudo:
     *   Agente 1001 (Local/1001@…/n) with penalty 1 (ringinuse disabled)
     *   (paused:Almoço was 0 secs ago) (Not in use) has taken no calls yet
     *
     * @return array<int,array<string,mixed>>
     */
    private function lerMembros(string $saida): array
    {
        $membros = [];

        foreach (explode("\n", $this->semCor($saida)) as $linha) {
            if (preg_match('/^\s{4,}(.+?)\s+\((\S+?)\)/', $linha, $m) !== 1) {
                continue;
            }

            preg_match('/with penalty (\d+)/', $linha, $pen);
            preg_match('/\(paused(?::([^)]*?))? was /', $linha, $pausa);
            preg_match('/\((Not in use|In use|Busy|Ringing|Ring\+Inuse|On Hold|Unavailable|Invalid|Unknown)\)/',
                       $linha, $est);
            preg_match('/has taken (\d+) calls/', $linha, $chamadas);

            $membros[] = [
                'nome'       => trim($m[1]),
                'interface'  => $m[2],
                'ramal'      => $this->ramalDaInterface($m[2]),
                'penalidade' => (int) ($pen[1] ?? 0),
                'estado'     => $est[1] ?? 'Desconhecido',
                'pausado'    => $pausa !== [],
                'motivo'     => trim($pausa[1] ?? '') ?: null,
                'chamadas'   => (int) ($chamadas[1] ?? 0),
            ];
        }

        return $membros;
    }

    /** "Local/1002@telium-confirma-3000/n" e "PJSIP/1002" dão 1002. */
    private function ramalDaInterface(string $interface): string
    {
        $sem = preg_replace('#^[A-Za-z]+/#', '', $interface) ?? $interface;

        return explode('@', explode('/', $sem)[0])[0];
    }

    /** "3000 has 2 calls (max 20) in …" */
    private function lerEsperando(string $saida): int
    {
        return preg_match('/\bhas (\d+) calls?\b/i', $this->semCor($saida), $m) === 1
            ? (int) $m[1]
            : 0;
    }

    private function semCor(string $texto): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $texto) ?? $texto;
    }
}
