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
            // Fila de call center: quem atende são pessoas, não ramais. A
            // lista é de todos os agentes, marcados os que estão nesta fila.
            'cc_agentes' => (int) $fila['callcenter'] === 1 ? Bd::todos(
                'SELECT a.id, a.matricula, a.ativo, a.nome, af.penalidade,
                        (af.agente_id IS NOT NULL) AS na_fila
                   FROM cc_agentes a
              LEFT JOIN cc_agente_filas af ON af.agente_id = a.id AND af.fila_id = ?
               ORDER BY na_fila DESC, a.nome',
                [$fila['id']]
            ) : [],
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

    /**
     * PUT /api/filas/{id}/cc-agentes {agentes: [{agente_id, penalidade}]}
     *
     * Quem atende numa fila de call center, pela tela da fila. É o mesmo
     * vínculo do cadastro de agentes (cc_agente_filas), visto do outro
     * lado; quem está logado entra, sai ou muda de nível na hora.
     */
    public function salvarAgentesCallCenter(Request $req, Response $res, array $args): Response
    {
        $fila = Bd::um('SELECT * FROM filas WHERE id = ?', [$args['id']]);
        if ($fila === null) {
            return Resposta::erro($res, 'Fila não encontrada', 404);
        }
        if ((int) $fila['callcenter'] !== 1) {
            return Resposta::erro($res, 'Esta fila não é de call center: monte os ramais dela na lista de agentes.', 409);
        }

        $novos = [];
        foreach ((array) (((array) $req->getParsedBody())['agentes'] ?? []) as $a) {
            $id = (int) ($a['agente_id'] ?? 0);
            if ($id > 0 && Bd::valor('SELECT id FROM cc_agentes WHERE id = ?', [$id])) {
                $novos[$id] = max(0, min(9, (int) ($a['penalidade'] ?? 0)));
            }
        }

        $antes = array_map('intval', array_column(
            Bd::todos('SELECT agente_id FROM cc_agente_filas WHERE fila_id = ?', [$fila['id']]), 'agente_id'
        ));

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            Bd::executar('DELETE FROM cc_agente_filas WHERE fila_id = ?', [$fila['id']]);
            foreach ($novos as $id => $penalidade) {
                Bd::executar('INSERT INTO cc_agente_filas (agente_id, fila_id, penalidade) VALUES (?, ?, ?)',
                             [$id, $fila['id'], $penalidade]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            return Resposta::erro($res, 'Não foi possível salvar os agentes: ' . $e->getMessage(), 400);
        }

        // Quem entrou, saiu ou mudou de nível e está logado agora.
        $aviso = null;
        try {
            $cc = \Telium\Dominio\CallCenter::compartilhado();
            foreach (array_unique([...$antes, ...array_keys($novos)]) as $id) {
                $cc->sincronizar((int) $id);
            }
        } catch (\Throwable) {
            $aviso = 'Salvo. O Asterisk não respondeu, então quem está logado só vê a mudança no próximo login.';
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'apps.filas',
                             (string) $fila['numero'], ['agentes_callcenter' => count($novos)]);

        return Resposta::json($res, ['ok' => true, 'agentes' => count($novos), 'aviso' => $aviso]);
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

            $gravados = 0;
            foreach ($lista as $a) {
                // Aceita o id do ramal ou o número dele: quem chama pela
                // API costuma ter o número na mão, e antes a entrada sem
                // ramal_id era descartada calada — a fila ficava sem
                // agente e a resposta dizia que tinha salvo.
                $ramalId = (int) ($a['ramal_id'] ?? 0);
                if ($ramalId === 0 && ($a['ramal'] ?? '') !== '') {
                    $ramalId = (int) (Bd::um('SELECT id FROM ramais WHERE numero = ?',
                                             [$a['ramal']])['id'] ?? 0);
                }
                if ($ramalId === 0) {
                    throw new \RuntimeException(
                        'Agente sem ramal reconhecido: ' . json_encode($a, JSON_UNESCAPED_UNICODE)
                    );
                }
                $gravados++;

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
                             (string) $fila['numero'], ['agentes' => $gravados]);

        return Resposta::json($res, ['ok' => true, 'agentes' => $gravados]);
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
