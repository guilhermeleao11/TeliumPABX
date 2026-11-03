<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * O resumo de uma seleção (a central, uma fila ou um ramal) num dia ou
 * num período: recebidas, feitas, completadas, pesquisa de satisfação.
 *
 * Nada aqui é estimado. Os números saem das mesmas contas das outras
 * telas — Chamadas para o CDR, RelatorioFilas para a fila, Pesquisas
 * para a pesquisa — e o relatório termina mostrando como cada total
 * fecha ("6 ligações = 4 recebidas + 2 feitas"). Se uma conta não fechar,
 * ela aparece marcada, em vez de ser escondida.
 */
final class RelatorioConsolidado
{
    /** Maior período de um relatório, em dias. */
    public const MAX_DIAS = 31;

    /** Os desfechos de uma ligação, na ordem em que o relatório os lista. */
    private const DESFECHOS = [
        'ANSWERED'   => 'Atendidas',
        'NO ANSWER'  => 'Não atendidas',
        'BUSY'       => 'Ocupado',
        'FAILED'     => 'Falharam',
        'CONGESTION' => 'Sem linha (congestionamento)',
    ];

    /**
     * @param 'central'|'fila'|'ramal' $tipo
     * @return array<string,mixed>
     */
    public static function gerar(string $tipo, string $alvo, string $de, string $ate): array
    {
        return match ($tipo) {
            'fila'  => self::daFila($alvo, $de, $ate),
            'ramal' => self::doRamal($alvo, $de, $ate),
            default => self::daCentral($de, $ate),
        };
    }

    // ------------------------------------------------------------------
    // Central inteira
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function daCentral(string $de, string $ate): array
    {
        $grupos = ['entrada' => self::vazio(), 'saida' => self::vazio(), 'interna' => self::vazio()];
        $porRamal = [];
        $porHora = self::horas();
        $nomes = self::nomesDosRamais();

        foreach (Chamadas::percorrer($de, $ate) as $c) {
            self::somar($grupos[$c['direcao']], $c);
            $chaveHora = ['entrada' => 'recebidas', 'saida' => 'feitas', 'interna' => 'internas'][$c['direcao']];
            $porHora[$c['hora']][$chaveHora]++;
            $porHora[$c['hora']]['atendidas'] += $c['atendida'] ? 1 : 0;

            foreach (self::ramaisDa($c) as $ramal => $papel) {
                // Chave numérica de array vira int no PHP; o ramal é texto.
                $ramal = (string) $ramal;
                $porRamal[$ramal] ??= self::linhaRamal($ramal, $nomes);
                self::somarRamal($porRamal[$ramal], $c, $papel, $ramal);
            }
        }

        $recebidas = $grupos['entrada'];
        $feitas = $grupos['saida'];
        $internas = $grupos['interna'];
        $total = $recebidas['total'] + $feitas['total'] + $internas['total'];
        $completadas = $recebidas['atendidas'] + $feitas['atendidas'] + $internas['atendidas'];
        $falado = $recebidas['falado'] + $feitas['falado'] + $internas['falado'];

        $filas = RelatorioFilas::doPeriodo($de, $ate)->filas();

        return self::montar(
            ['tipo' => 'central', 'alvo' => '', 'nome' => 'Toda a central',
             'descricao' => 'Todas as ligações da central: de fora, para fora e entre ramais.'],
            $de, $ate,
            [
                'total' => $total, 'recebidas' => $recebidas['total'], 'feitas' => $feitas['total'],
                'internas' => $internas['total'], 'completadas' => $completadas,
                'nao_completadas' => $total - $completadas, 'falado' => $falado,
                'tma' => $completadas > 0 ? intdiv($falado, max(1, self::comConversa($grupos))) : 0,
            ],
            [
                self::bloco('recebidas', 'Recebidas', 'Ligações que vieram de fora, pelos troncos.', $recebidas),
                self::bloco('feitas', 'Feitas', 'Ligações para fora, pelos troncos.', $feitas),
                self::bloco('internas', 'Internas', 'Ligações de ramal para ramal, que não saem da central.', $internas),
            ],
            self::pesquisa($de, $ate, null, null),
            self::ordenarRamais($porRamal),
            $porHora,
            array_map(static fn (array $f): array => [
                'numero' => $f['numero'], 'nome' => $f['nome'], 'recebidas' => $f['recebidas'],
                'atendidas' => $f['atendidas'], 'abandonadas' => $f['abandonadas'], 'sla' => $f['sla'],
            ], $filas),
            [
                'Uma ligação é contada uma vez, mesmo quando tocou em vários ramais ou passou por fila e pesquisa.',
                'Atendida é a ligação em que uma pessoa atendeu. A que só ouviu a URA, a fila ou a caixa postal conta como não atendida.',
                'Na tabela por ramal, a ligação interna aparece nos dois ramais: feita para um, recebida para o outro.',
            ]
        );
    }

    // ------------------------------------------------------------------
    // Uma fila
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function daFila(string $numero, string $de, string $ate): array
    {
        $fila = Bd::um('SELECT id, numero, nome, callcenter, sla_segundos FROM filas WHERE numero = ?', [$numero]);
        if ($fila === null) {
            throw new \DomainException("A fila {$numero} não existe.");
        }

        $rf = RelatorioFilas::doPeriodo($de, $ate, $numero);
        $q = $rf->filas()[0] ?? null;
        $membros = self::membros($fila);
        $ramaisDaFila = array_column($membros, 'ramal');

        // As feitas: ligações para fora dos ramais dos agentes da fila, e os
        // retornos do call center marcados com ela.
        $feitas = self::vazio();
        $porHora = self::horas();
        $feitasPorRamal = [];
        foreach (Chamadas::percorrer($de, $ate, ['direcao' => 'saida']) as $c) {
            $doRamal = $c['ramal_origem'] !== null && in_array($c['ramal_origem'], $ramaisDaFila, true);
            if (!$doRamal && !in_array($numero, $c['filas'], true)) {
                continue;
            }
            self::somar($feitas, $c);
            $porHora[$c['hora']]['feitas']++;
            $porHora[$c['hora']]['atendidas'] += $c['atendida'] ? 1 : 0;
            if ($c['ramal_origem'] !== null) {
                $r = $c['ramal_origem'];
                $feitasPorRamal[$r] ??= ['feitas' => 0, 'feitas_atendidas' => 0, 'falado' => 0];
                $feitasPorRamal[$r]['feitas']++;
                $feitasPorRamal[$r]['feitas_atendidas'] += $c['atendida'] ? 1 : 0;
                $feitasPorRamal[$r]['falado'] += $c['falado'];
            }
        }
        foreach ($rf->porHora() as $h) {
            $porHora[$h['hora']]['recebidas'] += $h['recebidas'];
            $porHora[$h['hora']]['atendidas'] += $h['atendidas'];
        }

        $rec = [
            'total' => (int) ($q['recebidas'] ?? 0), 'atendidas' => (int) ($q['atendidas'] ?? 0),
            'abandonadas' => (int) ($q['abandonadas'] ?? 0), 'estouradas' => (int) ($q['estouradas'] ?? 0),
            'sem_agente' => (int) ($q['sem_agente'] ?? 0), 'retorno' => (int) ($q['retorno'] ?? 0),
        ];
        $desfechos = [
            ['chave' => 'atendidas', 'rotulo' => 'Atendidas por um agente', 'n' => $rec['atendidas']],
            ['chave' => 'abandonadas', 'rotulo' => 'Desistiram esperando (abandono)', 'n' => $rec['abandonadas']],
            ['chave' => 'estouradas', 'rotulo' => 'Passaram do tempo máximo de espera', 'n' => $rec['estouradas']],
            ['chave' => 'sem_agente', 'rotulo' => 'Sem agente disponível', 'n' => $rec['sem_agente']],
            ['chave' => 'retorno', 'rotulo' => 'Pediram retorno', 'n' => $rec['retorno']],
        ];
        $resto = $rec['total'] - array_sum(array_column($desfechos, 'n'));
        if ($resto !== 0) {
            $desfechos[] = ['chave' => 'sem_desfecho', 'n' => $resto,
                            'rotulo' => 'Sem desfecho registrado (ainda na fila, ou a espera virou o dia)'];
        }
        $desfechos = array_values(array_filter($desfechos, static fn ($d) => $d['n'] !== 0 || $d['chave'] === 'atendidas'));

        // Por agente: as atendidas da fila (registro da fila) e as feitas (CDR).
        $porAgente = [];
        $ramalDoAgente = array_column($membros, 'ramal', 'membro');
        foreach ($rf->agentes() as $a) {
            $ramal = $ramalDoAgente[$a['agente']] ?? null;
            $f = $ramal !== null ? ($feitasPorRamal[$ramal] ?? null) : null;
            if ($a['atendidas'] === 0 && $f === null && (int) $a['nao_atendeu'] === 0) {
                continue;
            }
            $porAgente[] = [
                'ramal' => $ramal, 'nome' => $a['nome'], 'atendidas' => $a['atendidas'],
                'nao_atendeu' => (int) $a['nao_atendeu'], 'falado_recebidas' => (int) $a['falado'], 'tma' => $a['tma'],
                'feitas' => $f['feitas'] ?? 0, 'feitas_atendidas' => $f['feitas_atendidas'] ?? 0,
                'falado_feitas' => $f['falado'] ?? 0, 'nota' => $a['nota'], 'respostas' => $a['respostas'],
            ];
            if ($ramal !== null) {
                unset($feitasPorRamal[$ramal]);
            }
        }
        $nomes = self::nomesDosRamais();
        foreach ($feitasPorRamal as $ramal => $f) {
            $porAgente[] = [
                'ramal' => (string) $ramal, 'nome' => $nomes[$ramal] ?? (string) $ramal, 'atendidas' => 0,
                'nao_atendeu' => 0, 'falado_recebidas' => 0, 'tma' => 0, 'feitas' => $f['feitas'],
                'feitas_atendidas' => $f['feitas_atendidas'], 'falado_feitas' => $f['falado'], 'nota' => null, 'respostas' => 0,
            ];
        }
        usort($porAgente, static fn ($a, $b) => ($b['atendidas'] + $b['feitas']) <=> ($a['atendidas'] + $a['feitas'])
                                               ?: strcmp((string) $a['nome'], (string) $b['nome']));

        $faladoRec = array_sum(array_column($porAgente, 'falado_recebidas'));
        $total = $rec['total'] + $feitas['total'];
        $completadas = $rec['atendidas'] + $feitas['atendidas'];
        $conversas = $rec['atendidas'] + $feitas['com_conversa'];

        $membrosTexto = $membros === [] ? 'nenhum agente cadastrado'
            : implode(', ', array_map(static fn ($m) => "{$m['nome']} ({$m['ramal']})", $membros));

        return self::montar(
            ['tipo' => 'fila', 'alvo' => $numero, 'nome' => "Fila {$numero} — {$fila['nome']}",
             'descricao' => "Ligações que chegaram à fila {$fila['nome']} e ligações para fora feitas pelos ramais dos agentes dela.",
             'agentes' => $membros],
            $de, $ate,
            [
                'total' => $total, 'recebidas' => $rec['total'], 'feitas' => $feitas['total'], 'internas' => null,
                'completadas' => $completadas, 'nao_completadas' => $total - $completadas,
                'falado' => $faladoRec + $feitas['falado'],
                'tma' => $conversas > 0 ? intdiv($faladoRec + $feitas['falado'], $conversas) : 0,
            ],
            [
                [
                    'chave' => 'recebidas', 'titulo' => 'Recebidas na fila',
                    'descricao' => 'Ligações que chegaram à fila, pelo registro da própria fila.',
                    'total' => $rec['total'], 'atendidas' => $rec['atendidas'], 'desfechos' => $desfechos,
                    'indicadores' => [
                        ['rotulo' => 'Nível de serviço', 'valor' => $q['sla'] ?? null, 'unidade' => '%',
                         'ajuda' => "atendidas em até {$fila['sla_segundos']}s ÷ recebidas"],
                        ['rotulo' => 'Abandono', 'valor' => $q['abandono'] ?? null, 'unidade' => '%',
                         'ajuda' => 'abandonadas ÷ recebidas'],
                        ['rotulo' => 'Espera média até atender', 'valor' => $q['tme'] ?? 0, 'unidade' => 's'],
                        ['rotulo' => 'Maior espera até atender', 'valor' => $q['maior_espera'] ?? 0, 'unidade' => 's'],
                        ['rotulo' => 'Tempo médio de conversa', 'valor' => $q['tma'] ?? 0, 'unidade' => 's'],
                    ],
                ],
                self::bloco('feitas', 'Feitas pelos agentes', "Ligações para fora feitas pelos ramais dos agentes da fila ({$membrosTexto}), e os retornos marcados com a fila.", $feitas),
            ],
            self::pesquisa($de, $ate, $numero, null),
            $porAgente,
            $porHora,
            null,
            [
                'Recebidas e atendidas vêm do registro da fila (queue_log); feitas vêm do registro de chamadas (CDR).',
                'A ligação que tocou em vários agentes conta uma vez. Quem não atendeu porque um colega atendeu antes não perdeu a ligação.',
                'Ligações internas entre ramais não entram neste relatório de fila.',
                $membros === [] ? 'A fila não tem agente cadastrado: as feitas são só os retornos marcados com ela.'
                    : 'As feitas são dos ramais que são agentes da fila hoje. Quem saiu da fila não entra nas feitas.',
            ]
        );
    }

    /**
     * Os agentes da fila: ramal e nome com que a fila os registra.
     *
     * @param array<string,mixed> $fila
     * @return list<array{ramal:string, nome:string, membro:string}>
     */
    private static function membros(array $fila): array
    {
        if ((int) $fila['callcenter'] === 1) {
            return array_map(static fn (array $a): array => [
                'ramal' => (string) $a['ramal_padrao'], 'nome' => (string) $a['nome'],
                'membro' => CallCenter::nomeDoMembro((int) $a['id']),
            ], Bd::todos(
                "SELECT a.id, a.nome, a.ramal_padrao FROM cc_agente_filas af JOIN cc_agentes a ON a.id = af.agente_id
                  WHERE af.fila_id = ? AND a.ramal_padrao IS NOT NULL AND a.ramal_padrao <> '' ORDER BY a.nome",
                [(int) $fila['id']]
            ));
        }

        return array_map(static fn (array $r): array => [
            'ramal' => (string) $r['numero'], 'nome' => (string) $r['nome'],
            // O nome com que o queues.conf registra o membro (GeradorFilas).
            'membro' => str_replace(',', ' ', (string) $r['nome']),
        ], Bd::todos(
            'SELECT r.numero, r.nome FROM fila_agentes fa JOIN ramais r ON r.id = fa.ramal_id
              WHERE fa.fila_id = ? ORDER BY r.numero',
            [(int) $fila['id']]
        ));
    }

    // ------------------------------------------------------------------
    // Um ramal
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function doRamal(string $numero, string $de, string $ate): array
    {
        $r = Bd::um('SELECT numero, nome, setor FROM ramais WHERE numero = ?', [$numero]);
        if ($r === null) {
            throw new \DomainException("O ramal {$numero} não existe.");
        }

        $recebidas = self::vazio() + ['atendeu' => 0, 'outro_atendeu' => 0, 'de_fila' => 0];
        $feitasFora = self::vazio();
        $feitasDentro = self::vazio();
        $porHora = self::horas();
        $falado = 0;

        foreach (Chamadas::percorrer($de, $ate, ['ramal' => $numero]) as $c) {
            $papel = Chamadas::papel($c, $numero);
            if ($papel === null) {
                continue;
            }
            $meu = $c['falado_ramal'][$numero] ?? 0;
            $falado += $meu;
            if ($papel === 'feita') {
                $c['falado'] = $meu;
                if ($c['direcao'] === 'saida') {
                    self::somar($feitasFora, $c);
                } else {
                    self::somar($feitasDentro, $c);
                }
                $porHora[$c['hora']]['feitas']++;
                $porHora[$c['hora']]['atendidas'] += $c['atendida'] ? 1 : 0;
                continue;
            }
            $recebidas['total']++;
            $porHora[$c['hora']]['recebidas']++;
            $recebidas['de_fila'] += $c['filas'] !== [] ? 1 : 0;
            if (in_array($numero, $c['atenderam'], true)) {
                $recebidas['atendeu']++;
                $recebidas['falado'] += $meu;
                $recebidas['com_conversa'] += $meu > 0 ? 1 : 0;
                $porHora[$c['hora']]['atendidas']++;
            } elseif ($c['atendida']) {
                $recebidas['outro_atendeu']++;
            } else {
                $recebidas['nao'][$c['resultado']] = ($recebidas['nao'][$c['resultado']] ?? 0) + 1;
            }
        }

        $naoAtendidas = $recebidas['total'] - $recebidas['atendeu'] - $recebidas['outro_atendeu'];
        $feitas = $feitasFora['total'] + $feitasDentro['total'];
        $feitasAtendidas = $feitasFora['atendidas'] + $feitasDentro['atendidas'];
        $total = $recebidas['total'] + $feitas;
        $completadas = $recebidas['atendeu'] + $feitasAtendidas;
        $conversas = $recebidas['com_conversa'] + $feitasFora['com_conversa'] + $feitasDentro['com_conversa'];

        $blocoRecebidas = [
            'chave' => 'recebidas', 'titulo' => 'Recebidas',
            'descricao' => 'Ligações que tocaram neste ramal (de fora ou de outro ramal), e as de fila que ele atendeu.'
                . ($recebidas['de_fila'] > 0 ? " {$recebidas['de_fila']} vieram de fila." : ''),
            'total' => $recebidas['total'], 'atendidas' => $recebidas['atendeu'],
            'desfechos' => array_values(array_filter([
                ['chave' => 'atendeu', 'rotulo' => 'Atendidas por este ramal', 'n' => $recebidas['atendeu']],
                ['chave' => 'outro_atendeu', 'rotulo' => 'Atendidas por outro ramal (tocou junto, ou foi transferida)',
                 'n' => $recebidas['outro_atendeu']],
                ['chave' => 'nao_atendidas', 'rotulo' => 'Ninguém atendeu', 'n' => $naoAtendidas],
            ], static fn ($d) => $d['n'] !== 0 || $d['chave'] === 'atendeu')),
            'indicadores' => [],
        ];

        return self::montar(
            ['tipo' => 'ramal', 'alvo' => $numero, 'nome' => "Ramal {$numero} — {$r['nome']}",
             'descricao' => 'Ligações feitas e recebidas por este ramal' . ($r['setor'] ? " (setor {$r['setor']})." : '.')],
            $de, $ate,
            [
                'total' => $total, 'recebidas' => $recebidas['total'], 'feitas' => $feitas, 'internas' => null,
                'completadas' => $completadas, 'nao_completadas' => $total - $completadas, 'falado' => $falado,
                'tma' => $conversas > 0 ? intdiv($falado, $conversas) : 0,
            ],
            [
                $blocoRecebidas,
                self::bloco('feitas', 'Feitas para fora', 'Ligações deste ramal para números de fora.', $feitasFora),
                self::bloco('feitas_internas', 'Feitas para outros ramais', 'Ligações deste ramal para ramais, filas e serviços da central.', $feitasDentro),
            ],
            self::pesquisa($de, $ate, null, $numero),
            [],
            $porHora,
            null,
            [
                'Uma ligação é contada uma vez, mesmo quando passou por vários trechos (fila, transferência, pesquisa).',
                'Ligação de fila que tocou aqui e foi atendida por um colega não conta como recebida deste ramal: é da fila.',
                'O tempo falado é o deste ramal em cada ligação.',
            ],
            ['feitas_total' => $feitas, 'feitas_atendidas' => $feitasAtendidas]
        );
    }

    // ------------------------------------------------------------------
    // Pesquisa de satisfação
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> uma entrada por pesquisa que teve resposta no período */
    private static function pesquisa(string $de, string $ate, ?string $fila, ?string $ramal): array
    {
        $onde = 'criado_em >= ? AND criado_em <= ?';
        $args = [$de . ' 00:00:00', $ate . ' 23:59:59'];
        if ($fila !== null) {
            $onde .= ' AND fila = ?';
            $args[] = $fila;
        }
        if ($ramal !== null) {
            $onde .= ' AND (ramal = ? OR agente = ?)';
            array_push($args, $ramal, $ramal);
        }

        $saida = [];
        foreach (Bd::todos("SELECT DISTINCT pesquisa_id FROM pesquisa_respostas WHERE {$onde} ORDER BY pesquisa_id", $args) as $p) {
            $r = Pesquisas::resultados(['pesquisa' => (int) $p['pesquisa_id'], 'de' => $de, 'ate' => $ate,
                                        'fila' => $fila ?? '', 'ramal' => $ramal ?? '', 'status' => '']);
            if ($r === []) {
                continue;
            }
            $saida[] = [
                'pesquisa' => $r['pesquisa'],
                'resumo'   => $r['resumo'],
                'por_nota' => $r['por_nota'],
                'faixas'   => $r['faixas'],
            ];
        }

        return $saida;
    }

    // ------------------------------------------------------------------
    // Contas
    // ------------------------------------------------------------------

    /** "Atendidas por um agente" → "atendidas por um agente", para ler dentro de uma conta. */
    private static function minuscula(string $texto): string
    {
        return mb_strtolower(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }

    /** @return array<string,mixed> o acumulador de um grupo de ligações */
    private static function vazio(): array
    {
        return ['total' => 0, 'atendidas' => 0, 'falado' => 0, 'com_conversa' => 0, 'nao' => [], 'por_resultado' => []];
    }

    /** @param array<string,mixed> $g @param array<string,mixed> $c */
    private static function somar(array &$g, array $c): void
    {
        $g['total']++;
        $g['por_resultado'][$c['resultado']] = ($g['por_resultado'][$c['resultado']] ?? 0) + 1;
        if ($c['atendida']) {
            $g['atendidas']++;
            $g['falado'] += $c['falado'];
            $g['com_conversa'] += $c['falado'] > 0 ? 1 : 0;
        }
    }

    /** @param array<string,array<string,mixed>> $grupos */
    private static function comConversa(array $grupos): int
    {
        return array_sum(array_column($grupos, 'com_conversa'));
    }

    /**
     * Um bloco de ligações do CDR, com cada desfecho.
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private static function bloco(string $chave, string $titulo, string $descricao, array $g): array
    {
        $desfechos = [];
        foreach (self::DESFECHOS as $resultado => $rotulo) {
            $n = (int) ($g['por_resultado'][$resultado] ?? 0);
            if ($n > 0 || $resultado === 'ANSWERED') {
                $desfechos[] = ['chave' => $resultado, 'rotulo' => $rotulo, 'n' => $n];
            }
        }

        return [
            'chave' => $chave, 'titulo' => $titulo, 'descricao' => $descricao,
            'total' => $g['total'], 'atendidas' => $g['atendidas'], 'desfechos' => $desfechos,
            'indicadores' => [
                ['rotulo' => 'Tempo total de conversa', 'valor' => $g['falado'], 'unidade' => 's'],
                ['rotulo' => 'Tempo médio de conversa', 'valor' => $g['com_conversa'] > 0
                    ? intdiv($g['falado'], $g['com_conversa']) : 0, 'unidade' => 's'],
            ],
        ];
    }

    /** @return array<int,array<string,int>> as 24 horas zeradas */
    private static function horas(): array
    {
        $h = [];
        for ($i = 0; $i < 24; $i++) {
            $h[$i] = ['hora' => $i, 'recebidas' => 0, 'feitas' => 0, 'internas' => 0, 'atendidas' => 0];
        }

        return $h;
    }

    /** @return array<string,string> número => nome */
    private static function nomesDosRamais(): array
    {
        return array_map('strval', array_column(Bd::todos('SELECT numero, nome FROM ramais'), 'nome', 'numero'));
    }

    /**
     * Os ramais que a ligação envolve, com o papel de cada um.
     *
     * @param array<string,mixed> $c
     * @return array<string,string>
     */
    private static function ramaisDa(array $c): array
    {
        $r = [];
        if ($c['ramal_origem'] !== null) {
            $r[$c['ramal_origem']] = 'feita';
        }
        foreach ([...$c['tocaram'], ...$c['atenderam']] as $ramal) {
            $r[$ramal] ??= 'recebida';
        }

        return $r;
    }

    /** @param array<string,string> $nomes @return array<string,mixed> */
    private static function linhaRamal(string $ramal, array $nomes): array
    {
        return ['ramal' => $ramal, 'nome' => $nomes[$ramal] ?? $ramal, 'recebidas' => 0, 'atendidas' => 0,
                'outro_atendeu' => 0, 'nao_atendeu' => 0, 'feitas' => 0, 'feitas_atendidas' => 0, 'falado' => 0];
    }

    /** @param array<string,mixed> $l @param array<string,mixed> $c */
    private static function somarRamal(array &$l, array $c, string $papel, string $ramal): void
    {
        $l['falado'] += $c['falado_ramal'][$ramal] ?? 0;
        if ($papel === 'feita') {
            $l['feitas']++;
            $l['feitas_atendidas'] += $c['atendida'] ? 1 : 0;
            return;
        }
        $l['recebidas']++;
        if (in_array($ramal, $c['atenderam'], true)) {
            $l['atendidas']++;
        } elseif ($c['atendida']) {
            $l['outro_atendeu']++;
        } else {
            $l['nao_atendeu']++;
        }
    }

    /**
     * Cada ramal no período: recebidas (atendeu + outro atendeu + ninguém
     * atendeu), feitas e tempo falado. A tela Desempenho de Ramais usa esta
     * mesma conta, e por isso bate com o relatório consolidado.
     *
     * @return array<string,array<string,mixed>> número => linha
     */
    public static function porRamal(string $de, string $ate): array
    {
        $nomes = self::nomesDosRamais();
        $porRamal = [];
        foreach (Chamadas::percorrer($de, $ate) as $c) {
            foreach (self::ramaisDa($c) as $ramal => $papel) {
                $ramal = (string) $ramal;
                $porRamal[$ramal] ??= self::linhaRamal($ramal, $nomes);
                self::somarRamal($porRamal[$ramal], $c, $papel, $ramal);
            }
        }

        return $porRamal;
    }

    /**
     * @param array<string,array<string,mixed>> $porRamal
     * @return list<array<string,mixed>>
     */
    private static function ordenarRamais(array $porRamal): array
    {
        $l = array_values($porRamal);
        usort($l, static fn ($a, $b) => ($b['recebidas'] + $b['feitas']) <=> ($a['recebidas'] + $a['feitas'])
                                       ?: strcmp((string) $a['ramal'], (string) $b['ramal']));

        return $l;
    }

    /**
     * O relatório pronto, com a conferência de cada total.
     *
     * @param array<string,mixed>       $selecao
     * @param array<string,mixed>       $resumo
     * @param list<array<string,mixed>> $blocos
     * @param list<array<string,mixed>> $pesquisas
     * @param list<array<string,mixed>> $porAtendente
     * @param array<int,array<string,int>> $porHora
     * @param list<array<string,mixed>>|null $filas
     * @param list<string> $observacoes
     * @param array<string,int> $extra
     * @return array<string,mixed>
     */
    private static function montar(array $selecao, string $de, string $ate, array $resumo, array $blocos,
                                   array $pesquisas, array $porAtendente, array $porHora, ?array $filas,
                                   array $observacoes, array $extra = []): array
    {
        $conferencia = [];
        $confere = static function (string $descricao, int $total, array $partes) use (&$conferencia): void {
            $soma = array_sum(array_column($partes, 1));
            $conferencia[] = [
                'descricao' => $descricao,
                'conta' => "{$total} = " . ($partes === [] ? '0' : implode(' + ', array_map(
                    static fn ($p) => "{$p[1]} {$p[0]}", $partes))),
                'ok' => $soma === $total,
            ];
        };

        $partesTotal = [['recebidas', $resumo['recebidas']], ['feitas', $resumo['feitas']]];
        if ($resumo['internas'] !== null) {
            $partesTotal[] = ['internas', $resumo['internas']];
        }
        $confere('Total de ligações', $resumo['total'], $partesTotal);

        $completadas = [];
        foreach ($blocos as $b) {
            $confere($b['titulo'], $b['total'], array_map(static fn ($d) => [self::minuscula($d['rotulo']), $d['n']], $b['desfechos']));
            $completadas[] = [self::minuscula($b['titulo']) . ' atendidas', $b['atendidas']];
        }
        $confere('Completadas (alguém atendeu)', $resumo['completadas'], $completadas);

        foreach ($pesquisas as $p) {
            $r = $p['resumo'];
            $confere("Pesquisa \"{$p['pesquisa']['nome']}\"", $r['ofertadas'], [
                ['responderam', $r['respondidas']], ['não responderam', $r['sem_resposta']],
                ['tecla inválida', $r['invalidas']], ['desligaram no meio', $r['desligaram']],
            ]);
        }

        $somaHora = static fn (string $k): int => array_sum(array_column($porHora, $k));
        $confere('Soma das horas', $resumo['total'], array_values(array_filter([
            ['recebidas', $somaHora('recebidas')], ['feitas', $somaHora('feitas')],
            $resumo['internas'] !== null ? ['internas', $somaHora('internas')] : null,
        ])));

        if ($selecao['tipo'] === 'fila' && $porAtendente !== []) {
            $confere('Atendidas por agente', $blocos[0]['atendidas'], array_map(
                static fn ($a) => [$a['nome'], $a['atendidas']], array_filter($porAtendente, static fn ($a) => $a['atendidas'] > 0)
            ));
            $confere('Feitas por agente', $blocos[1]['total'], array_map(
                static fn ($a) => [$a['nome'], $a['feitas']], array_filter($porAtendente, static fn ($a) => $a['feitas'] > 0)
            ) + (($retornos = $blocos[1]['total'] - array_sum(array_column($porAtendente, 'feitas'))) > 0
                ? [999 => ['retornos sem ramal', $retornos]] : []));
        }

        return [
            'selecao'      => $selecao,
            'periodo'      => ['de' => $de, 'ate' => $ate, 'dias' => (int) (new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days + 1],
            'gerado_em'    => date('Y-m-d H:i:s'),
            'resumo'       => $resumo + $extra,
            'blocos'       => $blocos,
            'pesquisas'    => $pesquisas,
            'por_atendente' => $porAtendente,
            'por_hora'     => array_values(array_filter($porHora, static fn ($h) => $h['recebidas'] + $h['feitas'] + $h['internas'] > 0)),
            'filas'        => $filas,
            'conferencia'  => $conferencia,
            'confere'      => !in_array(false, array_column($conferencia, 'ok'), true),
            'observacoes'  => array_values(array_filter($observacoes)),
        ];
    }

    /**
     * O relatório em CSV, seção por seção, para o Excel em português.
     *
     * @param array<string,mixed> $r
     */
    public static function csv(array $r): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        $linha = static function (array $l) use ($fh): void {
            fputcsv($fh, array_map(static function ($v) {
                if (is_float($v)) {
                    return str_replace('.', ',', (string) $v);
                }
                if (is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0])) {
                    return "'" . $v;
                }
                return $v;
            }, $l), ';', '"', '');
        };
        $dataBr = static fn (string $d): string => implode('/', array_reverse(explode('-', $d)));

        $linha(['Relatório consolidado']);
        $linha(['Seleção', $r['selecao']['nome']]);
        $linha(['Período', $r['periodo']['de'] === $r['periodo']['ate'] ? $dataBr($r['periodo']['de'])
            : $dataBr($r['periodo']['de']) . ' a ' . $dataBr($r['periodo']['ate'])]);
        $linha(['Gerado em', $r['gerado_em']]);
        $linha([]);
        $linha(['Resumo']);
        $res = $r['resumo'];
        $linha(['Total de ligações', $res['total']]);
        $linha(['Recebidas', $res['recebidas']]);
        $linha(['Feitas', $res['feitas']]);
        if ($res['internas'] !== null) {
            $linha(['Internas', $res['internas']]);
        }
        $linha(['Completadas (alguém atendeu)', $res['completadas']]);
        $linha(['Não completadas', $res['nao_completadas']]);
        $linha(['Tempo total de conversa (s)', $res['falado']]);
        $linha(['Tempo médio de conversa (s)', $res['tma']]);

        foreach ($r['blocos'] as $b) {
            $linha([]);
            $linha([$b['titulo'], $b['total']]);
            foreach ($b['desfechos'] as $d) {
                $linha(['  ' . $d['rotulo'], $d['n']]);
            }
            foreach ($b['indicadores'] as $i) {
                $linha(['  ' . $i['rotulo'] . ($i['unidade'] === 's' ? ' (s)' : ' (%)'), $i['valor'] ?? '']);
            }
        }

        foreach ($r['pesquisas'] as $p) {
            $s = $p['resumo'];
            $linha([]);
            $linha(['Pesquisa de satisfação', $p['pesquisa']['nome'],
                    "nota de {$p['pesquisa']['nota_min']} a {$p['pesquisa']['nota_max']}"]);
            $linha(['  Oferecidas', $s['ofertadas']]);
            $linha(['  Responderam', $s['respondidas']]);
            $linha(['  Não responderam', $s['sem_resposta']]);
            $linha(['  Tecla inválida', $s['invalidas']]);
            $linha(['  Desligaram no meio', $s['desligaram']]);
            $linha(['  Nota média', $s['media_nota'] ?? '']);
            $linha(['  Satisfação (0 a 100)', $s['satisfacao'] ?? '']);
            foreach ($p['por_nota'] as $n) {
                $linha(['  Nota ' . $n['nota'], $n['quantidade']]);
            }
        }

        if ($r['por_atendente'] !== []) {
            $linha([]);
            $fila = $r['selecao']['tipo'] === 'fila';
            $linha($fila
                ? ['Por agente', 'Ramal', 'Atendidas', 'Não atendeu (tocou)', 'TMA (s)', 'Feitas', 'Feitas atendidas', 'Satisfação (0 a 100)']
                : ['Por ramal', 'Ramal', 'Recebidas', 'Atendeu', 'Ninguém atendeu', 'Feitas', 'Feitas atendidas', 'Tempo falado (s)']);
            foreach ($r['por_atendente'] as $a) {
                $linha($fila
                    ? [$a['nome'], $a['ramal'] ?? '', $a['atendidas'], $a['nao_atendeu'], $a['tma'], $a['feitas'], $a['feitas_atendidas'], $a['nota'] ?? '']
                    : [$a['nome'], $a['ramal'], $a['recebidas'], $a['atendidas'], $a['nao_atendeu'], $a['feitas'], $a['feitas_atendidas'], $a['falado']]);
            }
        }

        if ($r['filas'] !== null && $r['filas'] !== []) {
            $linha([]);
            $linha(['Por fila', 'Recebidas', 'Atendidas', 'Abandonadas', 'Nível de serviço (%)']);
            foreach ($r['filas'] as $f) {
                $linha(["{$f['numero']} {$f['nome']}", $f['recebidas'], $f['atendidas'], $f['abandonadas'], $f['sla'] ?? '']);
            }
        }

        $linha([]);
        $linha(['Hora', 'Recebidas', 'Feitas', ...($r['resumo']['internas'] !== null ? ['Internas'] : []), 'Atendidas']);
        foreach ($r['por_hora'] as $h) {
            $linha([sprintf('%02d:00', $h['hora']), $h['recebidas'], $h['feitas'],
                    ...($r['resumo']['internas'] !== null ? [$h['internas']] : []), $h['atendidas']]);
        }

        $linha([]);
        $linha(['Conferência', 'Conta', 'Fecha?']);
        foreach ($r['conferencia'] as $c) {
            $linha([$c['descricao'], $c['conta'], $c['ok'] ? 'sim' : 'NÃO']);
        }

        rewind($fh);
        $conteudo = (string) stream_get_contents($fh);
        fclose($fh);

        return $conteudo;
    }
}
