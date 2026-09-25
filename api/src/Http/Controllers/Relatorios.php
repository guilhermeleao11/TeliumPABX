<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Tarifa;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

final class Relatorios
{
    /** GET /api/cdr */
    public function cdr(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $where = [];
        $args = [];

        if (($p['de'] ?? '') !== '') {
            $where[] = 'calldate >= ?';
            $args[] = $p['de'] . ' 00:00:00';
        }
        if (($p['ate'] ?? '') !== '') {
            $where[] = 'calldate <= ?';
            $args[] = $p['ate'] . ' 23:59:59';
        }
        if (($p['direcao'] ?? '') !== '') {
            $where[] = 'direcao = ?';
            $args[] = $p['direcao'];
        }
        if (($p['status'] ?? '') !== '') {
            $where[] = 'disposition = ?';
            $args[] = $p['status'];
        }
        if (($p['q'] ?? '') !== '') {
            $where[] = '(src LIKE ? OR dst LIKE ? OR clid LIKE ?)';
            $args = [...$args, "%{$p['q']}%", "%{$p['q']}%", "%{$p['q']}%"];
        }

        $filtro = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $limite = min(200, max(10, (int) ($p['limite'] ?? 50)));
        $pagina = max(1, (int) ($p['pagina'] ?? 1));

        $total = (int) Bd::valor("SELECT COUNT(*) FROM cdr{$filtro}", $args);
        $linhas = Bd::todos(
            "SELECT id, calldate, clid, src, dst, direcao, duration, billsec,
                    disposition, tronco, motivo, fila, gravacao, custo
               FROM cdr{$filtro}
           ORDER BY calldate DESC
              LIMIT {$limite} OFFSET " . (($pagina - 1) * $limite),
            $args
        );

        return Resposta::json($res, [
            'dados' => $linhas,
            'total' => $total,
            'pagina' => $pagina,
            'limite' => $limite,
            'paginas' => (int) ceil($total / $limite),
        ]);
    }

    /**
     * GET /api/relatorios/ramais — quanto cada ramal falou.
     *
     * O CDR guarda origem e destino como texto, sem vínculo com a
     * tabela de ramais: o relatório junta pelo número, e um ramal
     * excluído continua aparecendo com o histórico dele — que é o que
     * se quer num relatório, e não o contrário.
     */
    public function ramais(Request $req, Response $res): Response
    {
        [$filtro, $args] = $this->periodo($req);

        $linhas = Bd::todos(
            "SELECT r.numero, r.nome, r.setor,
                    SUM(c.src = r.numero) AS feitas,
                    SUM(c.dst = r.numero) AS recebidas,
                    SUM(c.src = r.numero AND c.disposition = 'ANSWERED') AS feitas_ok,
                    SUM(c.dst = r.numero AND c.disposition = 'ANSWERED') AS recebidas_ok,
                    COALESCE(SUM(CASE WHEN c.src = r.numero THEN c.billsec END), 0) AS seg_feitas,
                    COALESCE(SUM(CASE WHEN c.dst = r.numero THEN c.billsec END), 0) AS seg_recebidas,
                    COALESCE(SUM(c.custo), 0) AS custo
               FROM ramais r
          LEFT JOIN cdr c ON (c.src = r.numero OR c.dst = r.numero){$filtro}
              WHERE r.ativo = 1
           GROUP BY r.id
           ORDER BY (COALESCE(SUM(c.billsec), 0)) DESC, r.numero",
            $args
        );

        foreach ($linhas as &$l) {
            $l['total'] = (int) $l['feitas'] + (int) $l['recebidas'];
            $l['segundos'] = (int) $l['seg_feitas'] + (int) $l['seg_recebidas'];
            $l['perdidas'] = (int) $l['recebidas'] - (int) $l['recebidas_ok'];
        }
        unset($l);

        return Resposta::json($res, ['dados' => $linhas]);
    }

    /** GET /api/relatorios/troncos — volume e falha por operadora. */
    public function troncos(Request $req, Response $res): Response
    {
        [$filtro, $args] = $this->periodo($req, 'c.');

        $linhas = Bd::todos(
            "SELECT t.nome, t.host, t.ativo,
                    COUNT(c.id) AS chamadas,
                    SUM(c.disposition = 'ANSWERED') AS atendidas,
                    SUM(c.disposition = 'BUSY') AS ocupadas,
                    SUM(c.disposition = 'FAILED') AS falhas,
                    SUM(c.disposition = 'NO ANSWER') AS sem_resposta,
                    COALESCE(SUM(c.billsec), 0) AS segundos,
                    COALESCE(SUM(c.custo), 0) AS custo
               FROM troncos t
          LEFT JOIN cdr c ON c.tronco = t.nome"
            . ($filtro === '' ? '' : ' AND ' . ltrim($filtro, ' WHERE')) . "
           GROUP BY t.id
           ORDER BY chamadas DESC, t.nome",
            $args
        );

        foreach ($linhas as &$l) {
            $total = (int) $l['chamadas'];
            // Taxa de completamento (ASR): é o número que se leva para a
            // operadora quando a reclamação é "a linha não completa".
            $l['asr'] = $total > 0 ? round((int) $l['atendidas'] / $total * 100, 1) : null;
            // Duração média da chamada atendida (ACD).
            $l['acd'] = (int) $l['atendidas'] > 0
                ? (int) round((int) $l['segundos'] / (int) $l['atendidas'])
                : 0;
        }
        unset($l);

        return Resposta::json($res, ['dados' => $linhas]);
    }

    /**
     * GET /api/relatorios/eventos — a linha do tempo de uma chamada.
     *
     * O CEL registra cada passo (atendeu, transferiu, entrou na ponte,
     * desligou). É o que responde "por que essa chamada caiu" quando o
     * CDR só diz que durou doze segundos.
     */
    public function eventos(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $chamada = trim((string) ($p['linkedid'] ?? $p['uniqueid'] ?? ''));

        if ($chamada !== '') {
            return Resposta::json($res, [
                'chamada' => $chamada,
                'dados'   => Bd::todos(
                    'SELECT eventtype, eventtime, cid_num, cid_name, exten, context,
                            channame, appname, appdata, uniqueid, linkedid
                       FROM cel WHERE linkedid = ? OR uniqueid = ?
                      ORDER BY eventtime, id',
                    [$chamada, $chamada]
                ),
            ]);
        }

        // Sem chamada escolhida, mostra as últimas para escolher uma.
        [$filtro, $args] = $this->periodo($req);
        $limite = min(200, max(10, (int) ($p['limite'] ?? 50)));

        return Resposta::json($res, [
            'chamada' => null,
            'dados'   => Bd::todos(
                "SELECT linkedid, MIN(eventtime) AS inicio, MAX(eventtime) AS fim,
                        COUNT(*) AS eventos,
                        SUBSTRING_INDEX(GROUP_CONCAT(cid_num ORDER BY eventtime), ',', 1) AS origem,
                        SUBSTRING_INDEX(GROUP_CONCAT(exten ORDER BY eventtime), ',', 1) AS destino
                   FROM cel"
                . str_replace('calldate', 'eventtime', $filtro) . "
               GROUP BY linkedid
               ORDER BY inicio DESC
                  LIMIT {$limite}",
                $args
            ),
        ]);
    }

    /**
     * Recorte de período comum aos relatórios.
     *
     * @return array{0:string, 1:list<string>}
     */
    private function periodo(Request $req, string $prefixo = ''): array
    {
        $p = $req->getQueryParams();
        $where = [];
        $args = [];

        if (($p['de'] ?? '') !== '') {
            $where[] = "{$prefixo}calldate >= ?";
            $args[] = $p['de'] . ' 00:00:00';
        }
        if (($p['ate'] ?? '') !== '') {
            $where[] = "{$prefixo}calldate <= ?";
            $args[] = $p['ate'] . ' 23:59:59';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
    }

    /** GET /api/relatorios/filas */
    public function filas(Request $req, Response $res): Response
    {
        $dias = min(90, max(1, (int) ($req->getQueryParams()['dias'] ?? 7)));

        $filas = Bd::todos(
            'SELECT f.numero, f.nome, f.sla_segundos,
                    (SELECT COUNT(*) FROM fila_agentes a WHERE a.fila_id = f.id) AS agentes
               FROM filas f WHERE f.ativo = 1 ORDER BY f.numero'
        );

        foreach ($filas as &$f) {
            $m = Bd::um(
                "SELECT COUNT(*) AS recebidas,
                        SUM(disposition = 'ANSWERED') AS atendidas,
                        AVG(NULLIF(billsec,0)) AS tma
                   FROM cdr
                  WHERE fila = ? AND calldate >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$f['numero'], $dias]
            ) ?? [];

            $recebidas = (int) ($m['recebidas'] ?? 0);
            $atendidas = (int) ($m['atendidas'] ?? 0);
            $f['recebidas'] = $recebidas;
            $f['atendidas'] = $atendidas;
            $f['abandonadas'] = $recebidas - $atendidas;
            $f['tma'] = (int) round((float) ($m['tma'] ?? 0));
            $f['sla'] = $recebidas > 0 ? round($atendidas / $recebidas * 100, 1) : null;
        }
        unset($f);

        $serie = Bd::todos(
            "SELECT DATE(calldate) AS dia,
                    SUM(disposition = 'ANSWERED') AS dentro,
                    SUM(disposition <> 'ANSWERED') AS fora
               FROM cdr
              WHERE fila IS NOT NULL AND calldate >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           GROUP BY DATE(calldate) ORDER BY dia",
            [$dias]
        );

        return Resposta::json($res, [
            'filas' => $filas,
            'serie' => [
                'labels' => array_map(
                    static fn (array $l): string => date('d/m', strtotime((string) $l['dia'])),
                    $serie
                ),
                'series' => [
                    ['key' => 'dentro', 'label' => 'Atendidas', 'color' => 'var(--series-1)',
                     'values' => array_map(static fn ($l) => (int) $l['dentro'], $serie)],
                    ['key' => 'fora', 'label' => 'Não atendidas', 'color' => 'var(--series-2)',
                     'values' => array_map(static fn ($l) => (int) $l['fora'], $serie)],
                ],
                'vazio' => $serie === [],
            ],
            'dias' => $dias,
        ]);
    }

    /** GET /api/relatorios/agentes */
    public function agentes(Request $req, Response $res): Response
    {
        $dias = min(90, max(1, (int) ($req->getQueryParams()['dias'] ?? 7)));

        return Resposta::json($res, [
            'dados' => Bd::todos(
                "SELECT r.numero AS ramal, r.nome,
                        COUNT(c.id) AS atendidas,
                        AVG(NULLIF(c.billsec,0)) AS tma
                   FROM ramais r
              LEFT JOIN cdr c ON c.src = r.numero
                            AND c.disposition = 'ANSWERED'
                            AND c.calldate >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  WHERE r.ativo = 1
               GROUP BY r.id, r.numero, r.nome
               ORDER BY atendidas DESC",
                [$dias]
            ),
            'dias' => $dias,
        ]);
    }

    /** GET /api/relatorios/tarifacao */
    public function tarifacao(Request $req, Response $res): Response
    {
        $mes = $req->getQueryParams()['mes'] ?? date('Y-m');

        $porSetor = Bd::todos(
            "SELECT COALESCE(r.setor, 'Sem setor') AS setor,
                    COUNT(*) AS chamadas,
                    ROUND(SUM(c.billsec) / 60) AS minutos,
                    COALESCE(SUM(c.custo), 0) AS custo
               FROM cdr c
          LEFT JOIN ramais r ON r.numero = c.src
              WHERE c.direcao = 'saida' AND DATE_FORMAT(c.calldate, '%Y-%m') = ?
           GROUP BY setor ORDER BY custo DESC",
            [$mes]
        );

        $totais = Bd::um(
            "SELECT COUNT(*) AS chamadas,
                    ROUND(SUM(billsec) / 60) AS minutos,
                    COALESCE(SUM(custo), 0) AS custo
               FROM cdr
              WHERE direcao = 'saida' AND DATE_FORMAT(calldate, '%Y-%m') = ?",
            [$mes]
        ) ?? [];

        // Chamada de saída que ainda não passou pela tarifação, e
        // chamada que passou e não achou tarifa: as duas fazem o total
        // do mês ficar menor do que a fatura, e a tela precisa dizer
        // isso em vez de deixar o número passar por resposta.
        $pendentes = (int) Bd::valor(
            "SELECT COUNT(*) FROM cdr
              WHERE direcao = 'saida' AND custo IS NULL AND billsec > 0
                AND DATE_FORMAT(calldate, '%Y-%m') = ?",
            [$mes]
        );

        $semClasse = (int) Bd::valor(
            "SELECT COUNT(*) FROM cdr
              WHERE direcao = 'saida' AND billsec > 0 AND (classe IS NULL OR classe = '')
                AND DATE_FORMAT(calldate, '%Y-%m') = ?",
            [$mes]
        );

        return Resposta::json($res, [
            'mes'        => $mes,
            'totais'     => $totais,
            'porSetor'   => $porSetor,
            'tarifas'    => Bd::todos('SELECT * FROM tarifas WHERE ativo = 1 ORDER BY ordem, id'),
            'zerado'     => Tarifa::tudoZerado(),
            'pendentes'  => $pendentes,
            'sem_classe' => $semClasse,
        ]);
    }

    /**
     * POST /api/relatorios/tarifacao — recalcula o mês agora.
     *
     * A tarifação roda sozinha pelo temporizador, mas quem acabou de
     * digitar o preço da operadora quer ver o mês fechado na hora, e não
     * no próximo ciclo.
     */
    public function tarifar(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        $mes = (string) ($c['mes'] ?? '');
        if ($mes !== '' && preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
            return Resposta::erro($res, 'Mês inválido.', 422, ['campo' => 'mes']);
        }

        $r = Tarifa::aplicar($mes === '' ? null : $mes);

        return Resposta::json($res, $r + [
            'detalhe' => $r['tarifadas'] === 0 && $r['sem_tarifa'] === 0
                ? 'Nenhuma chamada nova para tarifar neste mês.'
                : sprintf('%d chamada(s) tarifada(s).%s', $r['tarifadas'],
                    $r['sem_tarifa'] > 0
                        ? " {$r['sem_tarifa']} ficaram sem tarifa que se aplique — falta uma linha "
                        . 'para a classe delas na Tabela de Tarifas.'
                        : ''),
        ]);
    }

    /** GET /api/gravacoes */
    public function auditoria(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'dados' => Bd::todos(
                'SELECT id, usuario_nome, acao, modulo, objeto, ip, criado_em
                   FROM auditoria ORDER BY id DESC LIMIT 200'
            ),
        ]);
    }
}
