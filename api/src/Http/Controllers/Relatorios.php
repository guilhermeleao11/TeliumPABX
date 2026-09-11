<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
                    disposition, tronco, fila, gravacao, custo
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

        return Resposta::json($res, [
            'mes'      => $mes,
            'totais'   => $totais,
            'porSetor' => $porSetor,
            'tarifas'  => Bd::todos('SELECT * FROM tarifas WHERE ativo = 1 ORDER BY nome'),
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
