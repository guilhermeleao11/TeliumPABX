<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Os relatórios em PDF, no papel A4 da Telium: logo no cabeçalho, a faixa
 * azul da marca, numeração de página e quem gerou no rodapé.
 *
 * O HTML é escrito para o dompdf, que entende tabela e bloco, mas não
 * flex nem grid: o layout em colunas é feito com tabelas.
 */
final class RelatorioPdf
{
    private const AZUL = '#2958a5';
    private const CIANO = '#1997cd';
    private const PROFUNDO = '#0c629f';

    /**
     * O relatório consolidado em PDF.
     *
     * @param array<string,mixed> $r de RelatorioConsolidado::gerar()
     */
    public static function consolidado(array $r, string $quem): string
    {
        $res = $r['resumo'];
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $partesTotal = "{$res['recebidas']} recebidas + {$res['feitas']} feitas"
            . ($res['internas'] !== null ? " + {$res['internas']} internas" : '');

        $kpis = self::cartoes([
            ['Total de ligações', self::n($res['total']), $partesTotal, self::AZUL],
            ['Completadas', self::n($res['completadas']), self::pct($res['completadas'], $res['total']) . ' · alguém atendeu', '#1f8a4c'],
            ['Não completadas', self::n($res['nao_completadas']), self::pct($res['nao_completadas'], $res['total']) . ' do total',
             $res['nao_completadas'] > 0 ? '#c27c0e' : '#1f8a4c'],
            ['Tempo de conversa', self::tempo($res['falado']), 'média de ' . self::tempo($res['tma']) . ' por atendida', self::CIANO],
        ]);

        // Blocos de ligações e pesquisa, dois por linha.
        $caixas = array_map(static fn (array $b): string => self::bloco($b), $r['blocos']);
        foreach ($r['pesquisas'] as $p) {
            $caixas[] = self::pesquisa($p);
        }
        if ($r['pesquisas'] === []) {
            $caixas[] = self::caixa('Pesquisa de satisfação', '',
                '<p class="mudo">Nenhuma pesquisa foi oferecida nesta seleção no período.</p>');
        }
        $grade = '';
        foreach (array_chunk($caixas, 2) as $par) {
            $grade .= '<table class="grade"><tr><td class="col">' . $par[0] . '</td><td class="sep"></td><td class="col">'
                    . ($par[1] ?? '') . '</td></tr></table>';
        }

        $fila = $r['selecao']['tipo'] === 'fila';
        $atendentes = '';
        if ($r['por_atendente'] !== []) {
            $linhas = '';
            foreach ($r['por_atendente'] as $a) {
                $linhas .= $fila
                    ? "<tr><td>{$e($a['nome'])}</td><td class='mono'>{$e($a['ramal'] ?? '—')}</td><td class='num'>" . self::n($a['atendidas'])
                      . "</td><td class='num'>" . ($a['tma'] ? self::tempo($a['tma']) : '—') . "</td><td class='num'>" . self::n($a['nao_atendeu'])
                      . "</td><td class='num'>" . self::n($a['feitas']) . "</td><td class='num'>" . self::n($a['feitas_atendidas'])
                      . "</td><td class='num'>" . ($a['nota'] === null ? '—' : self::dec($a['nota']) . " <span class='mudo'>({$a['respostas']})</span>") . '</td></tr>'
                    : "<tr><td><b class='mono'>{$e($a['ramal'])}</b> {$e($a['nome'])}</td><td class='num'>" . self::n($a['recebidas'])
                      . "</td><td class='num'>" . self::n($a['atendidas']) . "</td><td class='num'>" . self::n($a['nao_atendeu'])
                      . "</td><td class='num'>" . self::n($a['feitas']) . "</td><td class='num'>" . self::n($a['feitas_atendidas'])
                      . "</td><td class='num'>" . self::tempo($a['falado']) . '</td></tr>';
            }
            $cab = $fila
                ? '<th>Agente</th><th>Ramal</th><th class="num">Atendidas</th><th class="num">TMA</th><th class="num">Não atendeu</th><th class="num">Feitas</th><th class="num">Feitas atend.</th><th class="num">Satisfação</th>'
                : '<th>Ramal</th><th class="num">Recebidas</th><th class="num">Atendeu</th><th class="num">Ninguém atendeu</th><th class="num">Feitas</th><th class="num">Feitas atend.</th><th class="num">Tempo falado</th>';
            $atendentes = self::secao($fila ? 'Por agente' : 'Por ramal',
                $fila ? 'Atendidas da fila e ligações feitas por cada agente.'
                      : 'Cada ramal com o que recebeu e o que fez. Uma ligação interna aparece nos dois ramais.',
                "<table class='dados'><thead><tr>{$cab}</tr></thead><tbody>{$linhas}</tbody></table>");
        }

        $filas = '';
        if (!empty($r['filas'])) {
            $linhas = '';
            foreach ($r['filas'] as $f) {
                $linhas .= "<tr><td><b class='mono'>{$e($f['numero'])}</b> {$e($f['nome'])}</td><td class='num'>" . self::n($f['recebidas'])
                    . "</td><td class='num'>" . self::n($f['atendidas']) . "</td><td class='num'>" . self::n($f['abandonadas'])
                    . "</td><td class='num'>" . ($f['sla'] === null ? '—' : self::dec($f['sla']) . '%') . '</td></tr>';
            }
            $filas = self::secao('Por fila', 'Das ligações recebidas, as que passaram por fila.',
                "<table class='dados'><thead><tr><th>Fila</th><th class='num'>Recebidas</th><th class='num'>Atendidas</th>"
                . "<th class='num'>Abandonadas</th><th class='num'>Nível de serviço</th></tr></thead><tbody>{$linhas}</tbody></table>");
        }

        $horas = '';
        if ($r['por_hora'] !== []) {
            $comInternas = $res['internas'] !== null;
            $maior = max(1, ...array_map(static fn ($h) => $h['recebidas'] + $h['feitas'] + $h['internas'], $r['por_hora']));
            $linhas = '';
            $soma = ['recebidas' => 0, 'feitas' => 0, 'internas' => 0, 'atendidas' => 0];
            foreach ($r['por_hora'] as $h) {
                $tot = $h['recebidas'] + $h['feitas'] + $h['internas'];
                foreach ($soma as $k => $_) {
                    $soma[$k] += $h[$k];
                }
                $linhas .= '<tr><td class="mono">' . sprintf('%02d:00', $h['hora']) . "</td><td class='num'>" . self::n($h['recebidas'])
                    . "</td><td class='num'>" . self::n($h['feitas']) . '</td>'
                    . ($comInternas ? "<td class='num'>" . self::n($h['internas']) . '</td>' : '')
                    . "<td class='num'><b>" . self::n($tot) . "</b></td><td class='num'>" . self::n($h['atendidas']) . '</td>'
                    . '<td class="barra-cel">' . self::barra($tot, $maior) . '</td></tr>';
            }
            $linhas .= "<tr class='soma'><td>Total</td><td class='num'>" . self::n($soma['recebidas']) . "</td><td class='num'>"
                . self::n($soma['feitas']) . '</td>' . ($comInternas ? "<td class='num'>" . self::n($soma['internas']) . '</td>' : '')
                . "<td class='num'>" . self::n($res['total']) . "</td><td class='num'>" . self::n($soma['atendidas']) . '</td><td></td></tr>';
            $horas = self::secao('Por hora', 'Cada ligação entra na hora em que começou.',
                "<table class='dados'><thead><tr><th>Hora</th><th class='num'>Recebidas</th><th class='num'>Feitas</th>"
                . ($comInternas ? "<th class='num'>Internas</th>" : '')
                . "<th class='num'>Total</th><th class='num'>Atendidas</th><th style='width:32%'></th></tr></thead><tbody>{$linhas}</tbody></table>");
        }

        $conf = '';
        foreach ($r['conferencia'] as $c) {
            $conf .= "<tr><td>{$e($c['descricao'])}</td><td class='mono pequeno'>{$e($c['conta'])}</td><td class='num'>"
                . ($c['ok'] ? "<span class='selo ok'>fecha</span>" : "<span class='selo erro'>não fecha</span>") . '</td></tr>';
        }
        $obs = implode('', array_map(static fn ($o) => "<li>{$e($o)}</li>", $r['observacoes']));
        $conferencia = self::secao('Conferência '
            . ($r['confere'] ? "<span class='selo ok'>todas as contas fecham</span>" : "<span class='selo erro'>há conta que não fecha</span>"),
            'Cada total e as partes que o formam.',
            "<table class='dados'><tbody>{$conf}</tbody></table><ul class='obs'>{$obs}</ul>");

        $periodo = self::periodo($r['periodo']['de'], $r['periodo']['ate'])
            . ($r['periodo']['dias'] > 1 ? " <span class='mudo'>({$r['periodo']['dias']} dias)</span>" : '');

        $corpo = "
            <table class='titulo'><tr>
              <td><div class='sobre'>Relatório consolidado</div>
                  <h1>{$e($r['selecao']['nome'])}</h1>
                  <div class='mudo'>{$e($r['selecao']['descricao'])}</div></td>
              <td class='periodo'><div class='sobre'>Período</div><div class='data'>{$periodo}</div></td>
            </tr></table>
            {$kpis}
            {$grade}
            {$atendentes}{$filas}{$horas}{$conferencia}";

        return self::documento('Relatório consolidado — ' . $r['selecao']['nome'], $r['selecao']['nome'],
                               self::periodo($r['periodo']['de'], $r['periodo']['ate']), $corpo, $quem, $r['gerado_em']);
    }

    /** Mais que isto vira um PDF de centenas de páginas, lento de gerar e que ninguém lê. */
    public const MAX_LIGACOES = 3000;

    /**
     * A lista de ligações do CDR em PDF, em A4 deitado: o resumo do filtro
     * no alto (as mesmas contas da tela) e uma linha por ligação.
     *
     * @param iterable<array<string,mixed>> $ligacoes de Chamadas::percorrer()
     * @param array<string,string> $filtros o que foi filtrado, para o cabeçalho
     */
    public static function ligacoes(iterable $ligacoes, string $de, string $ate, array $filtros, string $quem): string
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rotDir = ['entrada' => 'Recebida', 'saida' => 'Feita', 'interna' => 'Interna'];
        $cor = ['ANSWERED' => 'ok', 'BUSY' => 'aviso', 'NO ANSWER' => 'erro', 'FAILED' => 'erro', 'CONGESTION' => 'aviso'];

        $linhas = '';
        $t = ['total' => 0, 'entrada' => 0, 'saida' => 0, 'interna' => 0, 'atendidas' => 0, 'falado' => 0];
        foreach ($ligacoes as $c) {
            $t['total']++;
            $t[$c['direcao']]++;
            $t['atendidas'] += $c['atendida'] ? 1 : 0;
            $t['falado'] += $c['atendida'] ? $c['falado'] : 0;
            $quemAtendeu = $c['direcao'] === 'entrada'
                ? ($c['atenderam'] !== [] ? 'atendida por ' . implode(', ', $c['atenderam'])
                    : ($c['filas'] !== [] ? 'fila ' . implode(', ', $c['filas']) : '')) : '';
            $linhas .= '<tr><td class="mono">' . $e(date('d/m H:i:s', strtotime($c['inicio']))) . '</td>'
                . '<td>' . $rotDir[$c['direcao']] . '</td>'
                . "<td class='mono'>{$e($c['origem'])}" . ($c['nome'] ? "<div class='mudo pequeno'>{$e($c['nome'])}</div>" : '') . '</td>'
                . "<td class='mono'>{$e($c['destino'])}" . ($quemAtendeu !== '' ? "<div class='mudo pequeno'>{$e($quemAtendeu)}</div>" : '') . '</td>'
                . "<td><span class='selo {$cor[$c['resultado']]}'>" . Chamadas::rotulo($c['resultado']) . '</span></td>'
                . "<td class='num'>" . self::tempo($c['duracao']) . '</td>'
                . "<td class='num'>" . ($c['atendida'] ? self::tempo($c['falado']) : '—') . '</td>'
                . "<td style='padding-left:5mm'>" . $e(implode(', ', $c['troncos']) ?: '—') . '</td>'
                . "<td class='num'>" . ($c['custo'] !== null && $c['custo'] > 0 ? 'R$ ' . number_format($c['custo'], 2, ',', '.') : '—') . '</td>'
                . '</tr>';
        }

        $kpis = self::cartoes([
            ['Ligações', self::n($t['total']), "{$t['entrada']} recebidas + {$t['saida']} feitas + {$t['interna']} internas", self::AZUL],
            ['Atendidas', self::n($t['atendidas']), self::pct($t['atendidas'], $t['total']) . ' do total', '#1f8a4c'],
            ['Não atendidas', self::n($t['total'] - $t['atendidas']), self::pct($t['total'] - $t['atendidas'], $t['total']) . ' do total', '#c27c0e'],
            ['Tempo de conversa', self::tempo($t['falado']), 'média de ' . self::tempo($t['atendidas'] ? intdiv($t['falado'], $t['atendidas']) : 0) . ' por atendida', self::CIANO],
        ]);

        $filtro = implode(' · ', array_filter([
            ['entrada' => 'só recebidas', 'saida' => 'só feitas', 'interna' => 'só internas'][$filtros['direcao'] ?? ''] ?? '',
            ($filtros['resultado'] ?? '') !== '' ? 'resultado: ' . mb_strtolower(Chamadas::rotulo($filtros['resultado'])) : '',
            ($filtros['q'] ?? '') !== '' ? 'busca: "' . $filtros['q'] . '"' : '',
        ]));
        $periodo = self::periodo($de, $ate);

        $corpo = "
            <table class='titulo'><tr>
              <td><div class='sobre'>Registro de chamadas</div><h1>Ligações</h1>
                  <div class='mudo'>Uma linha por ligação, mesmo quando ela passou por fila, transferência ou pesquisa."
                  . ($filtro !== '' ? ' Filtro: ' . $e($filtro) . '.' : '') . "</div></td>
              <td class='periodo'><div class='sobre'>Período</div><div class='data'>{$periodo}</div></td>
            </tr></table>
            {$kpis}
            <table class='dados'><thead><tr><th>Quando</th><th>Sentido</th><th>Origem</th><th>Destino</th><th>Resultado</th>
              <th class='num'>Duração</th><th class='num'>Conversa</th><th style='padding-left:5mm'>Tronco</th><th class='num'>Custo</th></tr></thead>
            <tbody>" . ($linhas !== '' ? $linhas : "<tr><td colspan='9' class='mudo'>Nenhuma ligação no período.</td></tr>") . '</tbody></table>';

        return self::documento('Ligações — ' . $periodo, 'Registro de chamadas', $periodo, $corpo, $quem, date('Y-m-d H:i:s'), 'landscape');
    }

    // ------------------------------------------------------------------
    // Peças
    // ------------------------------------------------------------------

    /** @param list<array{0:string,1:string,2:string,3:string}> $cartoes */
    private static function cartoes(array $cartoes): string
    {
        $tds = [];
        foreach ($cartoes as [$rotulo, $valor, $rodape, $cor]) {
            $tds[] = "<td class='cartao' style='border-top:3px solid {$cor}'>
                <div class='rotulo'>" . htmlspecialchars($rotulo) . "</div>
                <div class='valor' style='color:{$cor}'>" . htmlspecialchars($valor) . "</div>
                <div class='rodape'>" . htmlspecialchars($rodape) . '</div></td>';
        }

        return "<table class='cartoes'><tr>" . implode("<td class='sep'></td>", $tds) . '</tr></table>';
    }

    /** @param array<string,mixed> $b */
    private static function bloco(array $b): string
    {
        $linhas = '';
        foreach ($b['desfechos'] as $d) {
            $linhas .= '<tr><td>' . htmlspecialchars($d['rotulo']) . "</td><td class='num'><b>" . self::n($d['n'])
                . "</b></td><td class='num mudo' style='width:15%'>" . self::pct($d['n'], $b['total']) . '</td></tr>';
        }
        $linhas .= "<tr class='soma'><td>Total</td><td class='num'>" . self::n($b['total']) . '</td><td></td></tr>';

        $ind = '';
        foreach (array_chunk($b['indicadores'], 2) as $par) {
            $ind .= '<tr>';
            foreach ($par as $i) {
                $valor = $i['valor'] === null ? '—' : ($i['unidade'] === 's' ? self::tempo($i['valor']) : self::dec($i['valor']) . '%');
                $ind .= "<td><div class='rotulo'>" . htmlspecialchars($i['rotulo']) . "</div><b>{$valor}</b></td>";
            }
            $ind .= count($par) === 1 ? '<td></td></tr>' : '</tr>';
        }

        return self::caixa(htmlspecialchars($b['titulo']) . " <span class='mudo'>· " . self::n($b['total']) . '</span>',
            htmlspecialchars($b['descricao']),
            "<table class='desfechos'>{$linhas}</table>" . ($ind !== '' ? "<table class='indicadores'>{$ind}</table>" : ''));
    }

    /** @param array<string,mixed> $p */
    private static function pesquisa(array $p): string
    {
        $s = $p['resumo'];
        $melhor = $p['pesquisa']['sentido'] === 'menor_melhor' ? $p['pesquisa']['nota_min'] : $p['pesquisa']['nota_max'];
        $linhas = "<tr><td>Responderam</td><td class='num'><b>" . self::n($s['respondidas']) . '</b></td></tr>'
            . "<tr><td>Não responderam</td><td class='num'><b>" . self::n($s['sem_resposta']) . '</b></td></tr>'
            . ($s['invalidas'] ? "<tr><td>Tecla inválida</td><td class='num'><b>" . self::n($s['invalidas']) . '</b></td></tr>' : '')
            . ($s['desligaram'] ? "<tr><td>Desligaram no meio</td><td class='num'><b>" . self::n($s['desligaram']) . '</b></td></tr>' : '')
            . "<tr class='soma'><td>Oferecidas</td><td class='num'>" . self::n($s['ofertadas']) . '</td></tr>';

        $maior = max(1, ...array_map(static fn ($n) => $n['quantidade'], $p['por_nota']));
        $notas = '';
        foreach ($p['por_nota'] as $n) {
            $notas .= "<tr><td class='mono' style='width:18%'>Nota {$n['nota']}</td><td>" . self::barra($n['quantidade'], $maior)
                . "</td><td class='num' style='width:12%'>" . self::n($n['quantidade']) . '</td></tr>';
        }

        $ind = "<table class='indicadores'><tr>
            <td><div class='rotulo'>Taxa de resposta</div><b>" . self::pct($s['respondidas'], $s['ofertadas']) . "</b></td>
            <td><div class='rotulo'>Nota média</div><b>" . ($s['media_nota'] === null ? '—' : self::dec($s['media_nota'])) . "</b></td>
            <td><div class='rotulo'>Satisfação (0 a 100)</div><b>" . ($s['satisfacao'] === null ? '—' : self::dec($s['satisfacao'])) . '</b></td>
          </tr></table>';

        return self::caixa('Pesquisa de satisfação',
            htmlspecialchars($p['pesquisa']['nome']) . " · nota de {$p['pesquisa']['nota_min']} a {$p['pesquisa']['nota_max']}, {$melhor} é a melhor",
            "{$ind}<table class='desfechos'>{$linhas}</table>"
            . ($s['respondidas'] ? "<table class='notas'>{$notas}</table>" : ''));
    }

    private static function caixa(string $titulo, string $sub, string $conteudo): string
    {
        return "<div class='caixa'><div class='caixa-titulo'>{$titulo}</div>"
            . ($sub !== '' ? "<div class='caixa-sub'>{$sub}</div>" : '') . $conteudo . '</div>';
    }

    private static function secao(string $titulo, string $sub, string $conteudo): string
    {
        return "<div class='secao'><h2>{$titulo}</h2><div class='caixa-sub'>" . htmlspecialchars($sub) . "</div>{$conteudo}</div>";
    }

    private static function barra(int $n, int $maior): string
    {
        $l = $maior > 0 ? max(0, min(100, (int) round(100 * $n / $maior))) : 0;

        return "<div class='trilho'>" . ($l > 0 ? "<div class='enche' style='width:{$l}%'></div>" : '') . '</div>';
    }

    // ------------------------------------------------------------------
    // Documento
    // ------------------------------------------------------------------

    private static function documento(string $titulo, string $selecao, string $periodo, string $corpo, string $quem,
                                      string $geradoEm, string $orientacao = 'portrait'): string
    {
        $logo = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/../../recursos/relatorio/telium-logo.jpg'));
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $gerado = date('d/m/Y \à\s H:i', strtotime($geradoEm));
        $azul = self::AZUL;
        $ciano = self::CIANO;
        $profundo = self::PROFUNDO;

        $html = <<<HTML
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>{$e($titulo)}</title>
<style>
  @page { size: A4 {$orientacao}; margin: 30mm 13mm 17mm 13mm; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.6pt; color: #1d2b3a; line-height: 1.35; }
  .topo { position: fixed; top: -23mm; left: 0; right: 0; height: 20mm; }
  .topo table { width: 100%; border-collapse: collapse; }
  .topo img { height: 10.5mm; }
  .topo .direita { text-align: right; vertical-align: bottom; font-size: 7.6pt; color: #5d6b7c; }
  .topo .direita b { color: {$profundo}; font-size: 8.4pt; }
  .faixa { height: 1.6mm; margin-top: 2.2mm; }
  .faixa div { height: 1.6mm; float: left; }
  .rodape-pag { position: fixed; bottom: -11mm; left: 0; right: 0; height: 8mm; font-size: 7pt; color: #7a8796;
                border-top: 0.4pt solid #d5dde8; padding-top: 1.6mm; }
  h1 { font-size: 17pt; margin: 1mm 0 1.2mm; color: #12233a; }
  h2 { font-size: 11pt; margin: 0 0 0.6mm; color: #12233a; }
  .sobre { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: {$ciano}; font-weight: bold; }
  .mudo { color: #6b7888; }
  .pequeno { font-size: 7.6pt; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  table { border-collapse: collapse; }
  .titulo { width: 100%; margin-bottom: 4mm; }
  .titulo td { vertical-align: bottom; }
  .titulo .periodo { text-align: right; width: 34%; }
  .titulo .data { font-size: 12pt; font-weight: bold; color: {$azul}; }
  .cartoes { width: 100%; margin-bottom: 4mm; }
  .cartao { width: 24%; background: #f4f7fb; border: 0.5pt solid #dfe6ef; padding: 2.6mm 3mm; vertical-align: top; }
  .cartao .rotulo { font-size: 7.4pt; color: #5d6b7c; font-weight: bold; }
  .cartao .valor { font-size: 19pt; font-weight: bold; margin: 0.8mm 0 0.4mm; }
  .cartao .rodape { font-size: 6.9pt; color: #6b7888; }
  .sep { width: 2.4%; }
  .grade { width: 100%; margin-bottom: 3.5mm; page-break-inside: avoid; }
  .grade .col { width: 48.8%; vertical-align: top; }
  .caixa { border: 0.5pt solid #dfe6ef; border-radius: 2mm; padding: 3mm 3.4mm; page-break-inside: avoid; }
  .caixa-titulo { font-weight: bold; font-size: 9.8pt; color: #12233a; }
  .caixa-sub { font-size: 7.3pt; color: #6b7888; margin: 0.4mm 0 2mm; }
  .desfechos { width: 100%; }
  .desfechos td { padding: 1.3mm 0.8mm; border-bottom: 0.4pt solid #e8edf3; }
  .soma td { border-top: 1pt solid #9fb0c6; border-bottom: none !important; font-weight: bold; }
  .num { text-align: right; }
  .indicadores { width: 100%; margin-top: 2.2mm; }
  .indicadores td { padding: 1mm 0.8mm; vertical-align: top; }
  .indicadores .rotulo { font-size: 6.8pt; color: #6b7888; }
  .indicadores b { font-size: 9.6pt; color: #12233a; }
  .notas { width: 100%; margin-top: 2mm; }
  .notas td { padding: 0.7mm 0.8mm; vertical-align: middle; }
  .trilho { height: 2.4mm; background: #e6ecf3; border-radius: 1.2mm; }
  .enche { height: 2.4mm; background: {$azul}; border-radius: 1.2mm; }
  .secao { margin-top: 3mm; margin-bottom: 1mm; page-break-inside: avoid; }
  h2, .caixa-sub { page-break-after: avoid; }
  .dados { width: 100%; margin-top: 1mm; }
  .dados th { background: {$azul}; color: #fff; font-size: 7.2pt; text-align: left; padding: 1.5mm 1.4mm; font-weight: bold; }
  .dados th.num { text-align: right; }
  .dados td { padding: 1.3mm 1.4mm; border-bottom: 0.4pt solid #e8edf3; vertical-align: middle; }
  .dados tbody tr:nth-child(even) td { background: #f7f9fc; }
  .dados tr.soma td { background: #fff; }
  .barra-cel { padding-left: 3mm !important; }
  .selo { font-size: 6.8pt; font-weight: bold; padding: 0.5mm 1.8mm; border-radius: 1.6mm; }
  .selo.ok { background: #dff3e7; color: #1f7a45; }
  .selo.erro { background: #fbe3e3; color: #b42323; }
  .selo.aviso { background: #fdf0d8; color: #9a6206; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .obs { margin: 2.4mm 0 0; padding-left: 4mm; color: #5d6b7c; font-size: 7.3pt; }
  .obs li { margin: 0.6mm 0; }
</style></head>
<body>
  <div class="topo">
    <table><tr>
      <td><img src="{$logo}" alt="Telium"></td>
      <td class="direita"><b>Telium PABX</b><br>{$e($selecao)} · {$e($periodo)}</td>
    </tr></table>
    <div class="faixa"><div style="width:62%;background:{$azul}"></div><div style="width:26%;background:{$profundo}"></div><div style="width:12%;background:{$ciano}"></div></div>
  </div>
  <div class="rodape-pag">Gerado em {$gerado}{$e($quem !== '' ? ' por ' . $quem : '')} · Telium PABX</div>
  {$corpo}
</body></html>
HTML;

        $op = new Options();
        $op->set('isRemoteEnabled', false);
        $op->set('isPhpEnabled', false);
        $op->set('defaultFont', 'DejaVu Sans');
        $op->set('chroot', realpath(__DIR__ . '/../../recursos'));
        // O dompdf guarda as métricas das fontes: num diretório que o
        // usuário da API pode escrever, não dentro do vendor.
        $cache = sys_get_temp_dir() . '/telium-dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0700, true);
        }
        $op->set('fontCache', $cache);
        $op->set('tempDir', $cache);

        $pdf = new Dompdf($op);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $orientacao);
        $pdf->render();
        $pdf->addInfo('Title', $titulo);
        $pdf->addInfo('Author', 'Telium PABX');
        $canvas = $pdf->getCanvas();
        $fonte = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 34.0 * 2.835 - 2, $canvas->get_height() - 13.6 * 2.835,
                           'Página {PAGE_NUM} de {PAGE_COUNT}', $fonte, 7, [0.48, 0.53, 0.59]);

        return (string) $pdf->output();
    }

    // ------------------------------------------------------------------
    // Números
    // ------------------------------------------------------------------

    private static function n(mixed $v): string
    {
        return number_format((float) $v, 0, ',', '.');
    }

    private static function dec(mixed $v): string
    {
        $s = number_format((float) $v, 1, ',', '.');

        return str_ends_with($s, ',0') ? substr($s, 0, -2) : $s;
    }

    private static function pct(int $n, int $de): string
    {
        return $de > 0 ? self::dec(100 * $n / $de) . '%' : '—';
    }

    private static function tempo(mixed $seg): string
    {
        $s = (int) round((float) $seg);
        if ($s <= 0) {
            return '0s';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $r = $s % 60;
        if ($h > 0) {
            return sprintf('%dh %02dmin', $h, $m);
        }

        return $m > 0 ? sprintf('%dmin %02ds', $m, $r) : "{$r}s";
    }

    private static function periodo(string $de, string $ate): string
    {
        $br = static fn (string $d): string => implode('/', array_reverse(explode('-', $d)));

        return $de === $ate ? $br($de) : $br($de) . ' a ' . $br($ate);
    }
}
