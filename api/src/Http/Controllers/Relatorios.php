<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Chamadas;
use Telium\Dominio\RelatorioConsolidado;
use Telium\Dominio\RelatorioFilas;
use Telium\Dominio\RelatorioPdf;
use Telium\Dominio\Tarifa;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

final class Relatorios
{
    /**
     * GET /api/cdr — as ligações do período, uma linha por ligação.
     *
     * Era uma linha por trecho do CDR, e o total da tela não batia com o
     * painel: a fila que tocou em dois agentes aparecia três vezes. Os
     * trechos continuam a um clique (GET /api/cdr/{chave}/trechos).
     */
    public function cdr(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        [$de, $ate] = $this->dias($p, 0);
        $limite = min(200, max(10, (int) ($p['limite'] ?? 50)));
        $pagina = max(1, (int) ($p['pagina'] ?? 1));

        $r = Chamadas::pagina($de, $ate, $this->filtroLigacoes($p), $limite, ($pagina - 1) * $limite);

        return Resposta::json($res, [
            'dados' => array_map(static fn (array $c): array => array_diff_key($c, ['falado_ramal' => 1]), $r['dados']),
            'total' => $r['total'],
            'pagina' => $pagina,
            'limite' => $limite,
            'paginas' => (int) ceil($r['total'] / $limite),
            'de' => $de,
            'ate' => $ate,
        ]);
    }

    /** GET /api/cdr/{chave}/trechos — os trechos de uma ligação, como o Asterisk gravou. */
    public function trechos(Request $req, Response $res, array $args): Response
    {
        return Resposta::json($res, ['dados' => Chamadas::trechos((string) $args['chave'])]);
    }

    /** @return array<string,string> os filtros da tela do CDR, no formato de Chamadas */
    private function filtroLigacoes(array $p): array
    {
        return [
            'direcao' => (string) ($p['direcao'] ?? ''),
            // "status" é o nome antigo do filtro: o resultado da ligação.
            'resultado' => (string) ($p['resultado'] ?? $p['status'] ?? ''),
            'q' => (string) ($p['q'] ?? ''),
        ];
    }

    /**
     * O período de um relatório, em dias: 'de' e 'ate' (AAAA-MM-DD).
     * Sem nada, o mês corrente (ou só hoje, com $padraoDias = 0). No
     * máximo um ano: cada dia é uma consulta.
     *
     * @return array{0:string, 1:string}
     */
    private function dias(array $p, ?int $padraoDias = null): array
    {
        $hoje = date('Y-m-d');
        $ate = Chamadas::dia($p['ate'] ?? '') ?? $hoje;
        $de = Chamadas::dia($p['de'] ?? '')
            ?? ($padraoDias === 0 ? $ate : date('Y-m-01', strtotime($ate)));
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        $limite = (new \DateTimeImmutable($ate))->modify('-365 days')->format('Y-m-d');

        return [max($de, $limite), $ate];
    }

    /** Teto da exportação: um ano de central grande cabe; uma planilha maior não abre. */
    private const CDR_MAX_EXPORTAR = 200000;

    /**
     * GET /api/cdr/exportar — o mesmo filtro da tela, em CSV.
     *
     * Uma linha por ligação, como a tela. Com trechos=1, uma por trecho do
     * CDR (o que o Asterisk gravou), para conferir com a operadora.
     *
     * Separador ";" e vírgula decimal, que é o que o Excel em português
     * abre sem perguntar nada.
     */
    public function exportarCdr(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        [$de, $ate] = $this->dias($p, 0);
        $trechos = !empty($p['trechos']);

        if (($p['formato'] ?? '') === 'pdf') {
            $filtros = $this->filtroLigacoes($p);
            $total = Chamadas::contar($de, $ate, $filtros);
            if ($total > RelatorioPdf::MAX_LIGACOES) {
                return Resposta::erro($res, sprintf(
                    'São %s ligações neste filtro; o PDF vai até %s. Diminua o período, ou use a planilha.',
                    number_format($total, 0, ',', '.'), number_format(RelatorioPdf::MAX_LIGACOES, 0, ',', '.')
                ), 422);
            }
            $usuario = (array) $req->getAttribute('usuario');
            \Telium\Dominio\Auditoria::registrar($usuario, 'exportar', 'rel.cdr', 'CDR (PDF)',
                ['linhas' => $total, 'de' => $de, 'ate' => $ate]);
            $res->getBody()->write(RelatorioPdf::ligacoes(Chamadas::percorrer($de, $ate, $filtros), $de, $ate, $filtros,
                                                          (string) ($usuario['nome'] ?? $usuario['login'] ?? '')));

            return $res->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="ligacoes-' . $de . ($ate !== $de ? '-a-' . $ate : '') . '.pdf"')
                ->withHeader('Cache-Control', 'no-store');
        }
        // Nome que comece com = + - @ vira fórmula no Excel de quem abre.
        $celula = static fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");

        if ($trechos) {
            [$filtro, $args] = $this->filtroCdr(['de' => $de, 'ate' => $ate] + $p);
            $total = (int) Bd::valor("SELECT COUNT(*) FROM cdr{$filtro}", $args);
            if ($total > self::CDR_MAX_EXPORTAR) {
                return Resposta::erro($res, sprintf(
                    'São %s trechos neste filtro; a exportação vai até %s. Diminua o período.',
                    number_format($total, 0, ',', '.'), number_format(self::CDR_MAX_EXPORTAR, 0, ',', '.')
                ), 422);
            }
            fputcsv($fh, ['Ligação', 'Data e hora', 'Origem', 'Nome de quem ligou', 'Destino', 'Canal de origem',
                          'Canal de destino', 'Sentido', 'Duração (s)', 'Falada (s)', 'Resultado', 'Tronco', 'Motivo',
                          'Fila', 'Custo (R$)', 'Gravação'], ';', '"', '');
            $ultimo = PHP_INT_MAX;
            do {
                $lote = Bd::todos(
                    "SELECT id, linkedid, calldate, clid, src, dst, channel, dstchannel, direcao, duration, billsec,
                            disposition, tronco, motivo, fila, custo, gravacao
                       FROM cdr{$filtro}" . ($filtro === '' ? ' WHERE' : ' AND') . " id < ?
                   ORDER BY id DESC LIMIT 5000",
                    [...$args, $ultimo]
                );
                foreach ($lote as $l) {
                    $ultimo = (int) $l['id'];
                    fputcsv($fh, array_map($celula, [
                        $l['linkedid'], $l['calldate'], $l['src'], $l['clid'], $l['dst'], $l['channel'], $l['dstchannel'],
                        $l['direcao'], (int) $l['duration'], (int) $l['billsec'], $l['disposition'], $l['tronco'],
                        $l['motivo'], $l['fila'], $l['custo'] === null ? '' : str_replace('.', ',', (string) $l['custo']),
                        $l['gravacao'] ? 'sim' : '',
                    ]), ';', '"', '');
                }
            } while (count($lote) === 5000);
        } else {
            $total = Chamadas::contar($de, $ate, $this->filtroLigacoes($p));
            if ($total > self::CDR_MAX_EXPORTAR) {
                return Resposta::erro($res, sprintf(
                    'São %s ligações neste filtro; a exportação vai até %s. Diminua o período.',
                    number_format($total, 0, ',', '.'), number_format(self::CDR_MAX_EXPORTAR, 0, ',', '.')
                ), 422);
            }
            fputcsv($fh, ['Ligação', 'Data e hora', 'Sentido', 'Origem', 'Nome de quem ligou', 'Destino', 'Resultado',
                          'Duração (s)', 'Conversa (s)', 'Ramal que fez', 'Ramais que atenderam', 'Tronco', 'Fila',
                          'Trechos no CDR', 'Custo (R$)', 'Gravação'], ';', '"', '');
            foreach (Chamadas::percorrer($de, $ate, $this->filtroLigacoes($p)) as $c) {
                fputcsv($fh, array_map($celula, [
                    $c['chave'], $c['inicio'], $c['direcao'], $c['origem'], $c['clid'], $c['destino'],
                    Chamadas::rotulo($c['resultado']), $c['duracao'], $c['falado'], $c['ramal_origem'] ?? '',
                    implode(' ', $c['atenderam']), implode(' ', $c['troncos']), implode(' ', $c['filas']), $c['trechos'],
                    $c['custo'] === null ? '' : str_replace('.', ',', (string) $c['custo']), $c['gravacao'] ? 'sim' : '',
                ]), ';', '"', '');
            }
        }

        rewind($fh);
        $conteudo = (string) stream_get_contents($fh);
        fclose($fh);

        \Telium\Dominio\Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'rel.cdr',
            $trechos ? 'CDR (trechos)' : 'CDR (ligações)', ['linhas' => $total, 'de' => $de, 'ate' => $ate]);

        $res->getBody()->write($conteudo);

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . ($trechos ? 'cdr-trechos-' : 'ligacoes-')
                . $de . ($ate !== $de ? '-a-' . $ate : '') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array{0:string, 1:list<string>} o WHERE do CDR, o mesmo da tela e da exportação */
    private function filtroCdr(array $p): array
    {
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

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
    }

    /**
     * GET /api/relatorios/ramais — o que cada ramal fez e recebeu.
     *
     * Uma ligação conta uma vez para cada ramal, pela mesma conta do
     * relatório consolidado. Antes cada trecho do CDR contava, o ramal era
     * achado pelo "src"/"dst" (no click to call o src é o cliente, na
     * fila o dst é o número da fila) e o ramal que atendeu a fila
     * aparecia sem nenhuma recebida.
     *
     * Recebidas = atendeu + outro atendeu + ninguém atendeu. "Outro
     * atendeu" é a que tocou junto (grupo de toque) ou foi transferida;
     * oferta de fila que um colega atendeu não conta para o ramal.
     */
    public function ramais(Request $req, Response $res): Response
    {
        [$de, $ate] = $this->dias($req->getQueryParams());
        $porRamal = RelatorioConsolidado::porRamal($de, $ate);

        $linhas = [];
        foreach (Bd::todos('SELECT numero, nome, setor, ativo FROM ramais ORDER BY numero') as $r) {
            $m = $porRamal[(string) $r['numero']] ?? null;
            unset($porRamal[(string) $r['numero']]);
            if ($m === null && (int) $r['ativo'] !== 1) {
                continue;
            }
            $linhas[] = self::linhaDoRamal($r, $m);
        }
        usort($linhas, static fn ($a, $b) => $b['segundos'] <=> $a['segundos'] ?: $b['total'] <=> $a['total']
                                             ?: strcmp((string) $a['numero'], (string) $b['numero']));

        return Resposta::json($res, ['dados' => $linhas, 'de' => $de, 'ate' => $ate]);
    }

    /** @param array<string,mixed> $r @param array<string,mixed>|null $m */
    private static function linhaDoRamal(array $r, ?array $m): array
    {
        $m ??= ['recebidas' => 0, 'atendidas' => 0, 'outro_atendeu' => 0, 'nao_atendeu' => 0,
                'feitas' => 0, 'feitas_atendidas' => 0, 'falado' => 0];

        return [
            'numero' => (string) $r['numero'], 'nome' => $r['nome'], 'setor' => $r['setor'],
            'feitas' => $m['feitas'], 'feitas_ok' => $m['feitas_atendidas'],
            'recebidas' => $m['recebidas'], 'recebidas_ok' => $m['atendidas'],
            'outro_atendeu' => $m['outro_atendeu'], 'perdidas' => $m['nao_atendeu'],
            'total' => $m['feitas'] + $m['recebidas'], 'segundos' => $m['falado'],
        ];
    }

    /**
     * GET /api/relatorios/troncos — volume e completamento por operadora.
     *
     * Por ligação, como as outras telas: a saída que dá ocupado grava dois
     * trechos no CDR, e a entrada de fila um por agente que tocou. O
     * completamento (ASR) é das feitas: é o número da operadora. Na
     * recebida, quem atende é a empresa, não a operadora.
     */
    public function troncos(Request $req, Response $res): Response
    {
        [$de, $ate] = $this->dias($req->getQueryParams());

        $vazio = ['recebidas' => 0, 'recebidas_ok' => 0, 'chamadas' => 0, 'atendidas' => 0, 'ocupadas' => 0,
                  'sem_resposta' => 0, 'falhas' => 0, 'congestionadas' => 0, 'segundos' => 0, 'custo' => 0.0];
        $porTronco = [];
        foreach (Chamadas::percorrer($de, $ate) as $c) {
            foreach ($c['troncos'] as $t) {
                $l = $porTronco[$t] ?? $vazio;
                if ($c['direcao'] === 'entrada') {
                    $l['recebidas']++;
                    $l['recebidas_ok'] += $c['atendida'] ? 1 : 0;
                } else {
                    $l['chamadas']++;
                    match ($c['resultado']) {
                        'ANSWERED' => $l['atendidas']++,
                        'BUSY' => $l['ocupadas']++,
                        'FAILED' => $l['falhas']++,
                        'CONGESTION' => $l['congestionadas']++,
                        default => $l['sem_resposta']++,
                    };
                }
                $l['segundos'] += $c['atendida'] ? $c['falado'] : 0;
                $l['custo'] += (float) ($c['custo'] ?? 0);
                $porTronco[$t] = $l;
            }
        }

        $linhas = [];
        foreach (Bd::todos('SELECT nome, host, ativo FROM troncos ORDER BY nome') as $t) {
            $linhas[] = $t + ($porTronco[(string) $t['nome']] ?? $vazio);
            unset($porTronco[(string) $t['nome']]);
        }
        // Tronco que já foi excluído, mas tem ligação no período.
        foreach ($porTronco as $nome => $l) {
            $linhas[] = ['nome' => (string) $nome, 'host' => null, 'ativo' => 0, 'excluido' => true] + $l;
        }

        foreach ($linhas as &$l) {
            $total = (int) $l['chamadas'];
            // Taxa de completamento (ASR) das feitas: é o número que se leva
            // para a operadora quando a reclamação é "a linha não completa".
            $l['asr'] = $total > 0 ? round((int) $l['atendidas'] / $total * 100, 1) : null;
            // Duração média da ligação atendida (ACD), das duas direções.
            $atendidas = (int) $l['atendidas'] + (int) $l['recebidas_ok'];
            $l['acd'] = $atendidas > 0 ? (int) round((int) $l['segundos'] / $atendidas) : 0;
            $l['custo'] = round((float) $l['custo'], 2);
        }
        unset($l);
        usort($linhas, static fn ($a, $b) => ($b['chamadas'] + $b['recebidas']) <=> ($a['chamadas'] + $a['recebidas'])
                                             ?: strcmp((string) $a['nome'], (string) $b['nome']));

        return Resposta::json($res, ['dados' => $linhas, 'de' => $de, 'ate' => $ate]);
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

    /**
     * GET /api/relatorios/filas
     *
     * Do queue_log, não do CDR: o CDR tinha uma linha por agente que a
     * chamada tocou, e o "SLA" era atendidas ÷ recebidas sem olhar tempo.
     */
    public function filas(Request $req, Response $res): Response
    {
        $dias = min(90, max(1, (int) ($req->getQueryParams()['dias'] ?? 7)));
        $r = RelatorioFilas::doPeriodo(date('Y-m-d', strtotime("-{$dias} days + 1 day")), date('Y-m-d'));

        $porNumero = array_column($r->filas(), null, 'numero');
        $filas = Bd::todos(
            'SELECT f.numero, f.nome, f.sla_segundos, f.callcenter,
                    IF(f.callcenter = 1,
                       (SELECT COUNT(*) FROM cc_agente_filas a WHERE a.fila_id = f.id),
                       (SELECT COUNT(*) FROM fila_agentes a WHERE a.fila_id = f.id)) AS agentes
               FROM filas f WHERE f.ativo = 1 ORDER BY f.numero'
        );
        foreach ($filas as &$f) {
            $m = $porNumero[(string) $f['numero']] ?? null;
            foreach (['recebidas', 'atendidas', 'abandonadas', 'estouradas', 'sem_agente', 'retorno', 'no_sla', 'tme', 'tma'] as $k) {
                $f[$k] = (int) ($m[$k] ?? 0);
            }
            $f['sla'] = $m['sla'] ?? null;
        }
        unset($f);

        $serie = $r->porDia();

        return Resposta::json($res, [
            'filas' => $filas,
            'serie' => [
                'labels' => array_map(
                    static fn (array $l): string => date('d/m', strtotime((string) $l['dia'])),
                    $serie
                ),
                'series' => [
                    ['key' => 'dentro', 'label' => 'Atendidas', 'color' => 'var(--series-1)',
                     'values' => array_map(static fn ($l) => $l['atendidas'], $serie)],
                    ['key' => 'fora', 'label' => 'Não atendidas', 'color' => 'var(--series-2)',
                     'values' => array_map(static fn ($l) => max(0, $l['recebidas'] - $l['atendidas']), $serie)],
                ],
                'vazio' => $serie === [],
            ],
            'dias' => $dias,
        ]);
    }

    /**
     * GET /api/relatorios/agentes
     *
     * Quem atendeu chamada de fila, pelo queue_log. Antes contava as
     * chamadas que o ramal FEZ — o oposto do que o nome dizia.
     */
    public function agentes(Request $req, Response $res): Response
    {
        $dias = min(90, max(1, (int) ($req->getQueryParams()['dias'] ?? 7)));
        $r = RelatorioFilas::doPeriodo(date('Y-m-d', strtotime("-{$dias} days + 1 day")), date('Y-m-d'));

        return Resposta::json($res, [
            // "identificacao": a matrícula de quem é agente do call center;
            // nas filas comuns, o membro como a fila o registra. Antes a
            // coluna se chamava "ramal" e repetia o nome.
            'dados' => array_map(static fn (array $a): array => [
                'identificacao' => $a['matricula'] ?? $a['agente'],
                'callcenter'    => $a['matricula'] !== null,
                'nome'          => $a['nome'],
                'atendidas'     => $a['atendidas'],
                'tma'           => $a['tma'],
                'nao_atendeu'   => $a['nao_atendeu'],
                'pausado'       => $a['pausado'],
                'nota'          => $a['nota'],
                'respostas'     => $a['respostas'],
            ], $r->agentes()),
            'dias' => $dias,
        ]);
    }

    /**
     * GET /api/relatorios/consolidado?tipo=central|fila|ramal&alvo=&de=&ate=[&formato=csv]
     *
     * O resumo de uma seleção num dia ou num período. Sem tipo, devolve só
     * as opções da tela (filas e ramais).
     */
    public function consolidado(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $tipo = (string) ($p['tipo'] ?? '');
        if ($tipo === '') {
            return Resposta::json($res, [
                'filas'  => Bd::todos('SELECT numero, nome FROM filas ORDER BY numero'),
                'ramais' => Bd::todos('SELECT numero, nome FROM ramais ORDER BY CAST(numero AS UNSIGNED), numero'),
                'max_dias' => RelatorioConsolidado::MAX_DIAS,
            ]);
        }
        if (!in_array($tipo, ['central', 'fila', 'ramal'], true)) {
            return Resposta::erro($res, 'Escolha a central inteira, uma fila ou um ramal.', 422, ['campo' => 'tipo']);
        }
        $alvo = trim((string) ($p['alvo'] ?? ''));
        if ($tipo !== 'central' && $alvo === '') {
            return Resposta::erro($res, $tipo === 'fila' ? 'Escolha a fila.' : 'Escolha o ramal.', 422, ['campo' => 'alvo']);
        }
        $de = Chamadas::dia($p['de'] ?? '');
        $ate = Chamadas::dia(($p['ate'] ?? '') !== '' ? $p['ate'] : ($p['de'] ?? ''));
        if ($de === null || $ate === null) {
            return Resposta::erro($res, 'Informe o dia (AAAA-MM-DD).', 422, ['campo' => 'de']);
        }
        if ($de > $ate) {
            return Resposta::erro($res, 'O início do período vem depois do fim.', 422, ['campo' => 'ate']);
        }
        $dias = (new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days + 1;
        if ($dias > RelatorioConsolidado::MAX_DIAS) {
            return Resposta::erro($res, 'O relatório vai até ' . RelatorioConsolidado::MAX_DIAS
                . " dias de cada vez; o período escolhido tem {$dias}.", 422, ['campo' => 'ate']);
        }

        try {
            $r = RelatorioConsolidado::gerar($tipo, $alvo, $de, $ate);
        } catch (\DomainException $e) {
            return Resposta::erro($res, $e->getMessage(), 404, ['campo' => 'alvo']);
        }

        $formato = (string) ($p['formato'] ?? '');
        if (!in_array($formato, ['csv', 'pdf'], true)) {
            return Resposta::json($res, $r);
        }

        $usuario = (array) $req->getAttribute('usuario');
        \Telium\Dominio\Auditoria::registrar($usuario, 'exportar', 'rel.consolidado',
            $r['selecao']['nome'], ['de' => $de, 'ate' => $ate, 'formato' => $formato]);
        $nome = 'relatorio-' . $tipo . ($alvo !== '' ? '-' . preg_replace('/[^0-9A-Za-z_-]/', '', $alvo) : '')
              . '-' . $de . ($ate !== $de ? '-a-' . $ate : '') . '.' . $formato;

        if ($formato === 'pdf') {
            $res->getBody()->write(RelatorioPdf::consolidado($r, (string) ($usuario['nome'] ?? $usuario['login'] ?? '')));

            return $res->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $nome . '"')
                ->withHeader('Cache-Control', 'no-store');
        }

        $res->getBody()->write(RelatorioConsolidado::csv($r));

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nome . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** GET /api/relatorios/tarifacao */
    public function tarifacao(Request $req, Response $res): Response
    {
        $mes = $req->getQueryParams()['mes'] ?? date('Y-m');

        // Uma ligação conta uma vez: a saída que dá ocupado grava dois
        // trechos no CDR. O setor é o do ramal do canal, não do "src" — no
        // click to call o src é o número do cliente.
        $porSetor = Bd::todos(
            "SELECT COALESCE(r.setor, 'Sem setor') AS setor,
                    COUNT(DISTINCT IF(c.linkedid <> '', c.linkedid, c.uniqueid)) AS chamadas,
                    ROUND(SUM(c.billsec) / 60) AS minutos,
                    COALESCE(SUM(c.custo), 0) AS custo
               FROM cdr c
          LEFT JOIN ramais r ON r.numero = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(c.channel, '-', 1), '/', -1), '@', 1)
              WHERE c.direcao = 'saida' AND DATE_FORMAT(c.calldate, '%Y-%m') = ?
           GROUP BY setor ORDER BY custo DESC",
            [$mes]
        );

        $totais = Bd::um(
            "SELECT COUNT(DISTINCT IF(linkedid <> '', linkedid, uniqueid)) AS chamadas,
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
            "SELECT COUNT(DISTINCT IF(linkedid <> '', linkedid, uniqueid)) FROM cdr
              WHERE direcao = 'saida' AND custo IS NULL AND billsec > 0
                AND DATE_FORMAT(calldate, '%Y-%m') = ?",
            [$mes]
        );

        $semClasse = (int) Bd::valor(
            "SELECT COUNT(DISTINCT IF(linkedid <> '', linkedid, uniqueid)) FROM cdr
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

        // Recalcular sem mês escolhido é o mês corrente, que é o que a tela mostra.
        $refazer = !empty($c['refazer']);
        if ($refazer && $mes === '') {
            $mes = date('Y-m');
        }
        $r = Tarifa::aplicar($mes === '' ? null : $mes, 20000, $refazer);

        return Resposta::json($res, $r + [
            'detalhe' => $r['tarifadas'] === 0 && $r['sem_tarifa'] === 0
                ? 'Nenhuma chamada de saída para tarifar neste mês.'
                : sprintf('%d chamada(s) %s.%s', $r['tarifadas'], $refazer ? 'recalculada(s) com os preços de agora' : 'tarifada(s)',
                    $r['sem_tarifa'] > 0
                        ? " {$r['sem_tarifa']} ficaram sem tarifa que se aplique — falta uma linha "
                        . 'para a classe delas na Tabela de Tarifas.'
                        : ''),
        ]);
    }

    /** Linhas por página na auditoria. */
    private const AUDITORIA_POR_PAGINA = 30;
    /** Maior período de uma consulta da auditoria, em dias. */
    private const AUDITORIA_MAX_DIAS = 92;

    /**
     * GET /api/auditoria — filtrada e em páginas de 30.
     *
     * A tabela só cresce: cada alteração, login e aplicação vira uma
     * linha. Trazer as 200 últimas sem filtro nenhum não achava o que
     * se procurava e ficava mais pesado a cada mês. Aqui a consulta tem
     * sempre período (os últimos 7 dias, se nada vier; no máximo 92),
     * que é o índice da data que corta, e não conta o total: busca uma
     * linha a mais só para saber se há próxima página.
     *
     * Filtros: de, ate (AAAA-MM-DD), usuario, acao, modulo, q (objeto ou
     * IP) e pagina.
     */
    public function auditoria(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $data = static function (mixed $v): ?\DateTimeImmutable {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
            return $d !== false && $d->format('Y-m-d') === (string) $v ? $d : null;
        };

        $ate = $data($p['ate'] ?? '') ?? new \DateTimeImmutable('today');
        $de = $data($p['de'] ?? '') ?? $ate->modify('-6 days');
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        // Período longo demais vira o máximo, contado do fim.
        $limiteDe = $ate->modify('-' . (self::AUDITORIA_MAX_DIAS - 1) . ' days');
        if ($de < $limiteDe) {
            $de = $limiteDe;
        }

        $where = ['criado_em >= ?', 'criado_em < ?'];
        $args = [$de->format('Y-m-d 00:00:00'), $ate->modify('+1 day')->format('Y-m-d 00:00:00')];

        foreach (['acao', 'modulo'] as $campo) {
            if (trim((string) ($p[$campo] ?? '')) !== '') {
                $where[] = "{$campo} = ?";
                $args[] = trim((string) $p[$campo]);
            }
        }
        if (trim((string) ($p['usuario'] ?? '')) !== '') {
            $where[] = 'usuario_nome LIKE ?';
            $args[] = '%' . addcslashes(trim((string) $p['usuario']), '%_\\') . '%';
        }
        if (trim((string) ($p['q'] ?? '')) !== '') {
            $termo = '%' . addcslashes(trim((string) $p['q']), '%_\\') . '%';
            $where[] = '(objeto LIKE ? OR ip LIKE ?)';
            $args = [...$args, $termo, $termo];
        }

        $pagina = min(1000, max(1, (int) ($p['pagina'] ?? 1)));
        $porPagina = self::AUDITORIA_POR_PAGINA;
        $linhas = Bd::todos(
            'SELECT id, usuario_nome, acao, modulo, objeto, ip, criado_em
               FROM auditoria
              WHERE ' . implode(' AND ', $where) . '
           ORDER BY criado_em DESC, id DESC
              LIMIT ' . ($porPagina + 1) . ' OFFSET ' . (($pagina - 1) * $porPagina),
            $args
        );
        $temMais = count($linhas) > $porPagina;

        return Resposta::json($res, [
            'dados'    => array_slice($linhas, 0, $porPagina),
            'pagina'   => $pagina,
            'limite'   => $porPagina,
            'tem_mais' => $temMais,
            'de'       => $de->format('Y-m-d'),
            'ate'      => $ate->format('Y-m-d'),
            'max_dias' => self::AUDITORIA_MAX_DIAS,
            // Os módulos para o seletor: o índice do módulo responde sem
            // ler a tabela.
            'modulos'  => array_map('strval', array_column(
                Bd::todos('SELECT DISTINCT modulo FROM auditoria ORDER BY modulo'), 'modulo'
            )),
        ]);
    }
}
