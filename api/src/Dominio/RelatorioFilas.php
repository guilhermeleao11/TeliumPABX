<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Números de fila e de agente, tirados do queue_log.
 *
 * Os relatórios de fila contavam linhas de CDR: uma chamada que tocava
 * em três agentes virava três "recebidas", o SLA era atendidas ÷
 * recebidas (sem olhar tempo nenhum) e a produtividade do agente era
 * quantas chamadas o ramal FEZ. O queue_log é o que o app_queue escreve
 * sobre cada chamada da fila, uma vez, com espera e conversa medidas por
 * ele — é a fonte certa.
 *
 * Os eventos que importam (data1…data3 conforme o evento):
 *   ENTERQUEUE                         entrou na fila
 *   CONNECT         espera             atendida
 *   COMPLETECALLER  espera, conversa   terminou (desligou o cliente)
 *   COMPLETEAGENT   espera, conversa   terminou (desligou o agente)
 *   ABANDON         posição, _, espera desistiu esperando
 *   EXITWITHTIMEOUT posição, _, espera estourou o tempo máximo
 *   EXITEMPTY       posição, _, espera saiu porque não havia agente
 *   EXITWITHKEY     tecla, posição     pediu retorno (ou saiu por tecla)
 *   RINGNOANSWER    tempo tocando      o agente não atendeu
 *   ADDMEMBER / REMOVEMEMBER           agente entrou / saiu
 *   PAUSE(ALL) / UNPAUSE(ALL)  motivo  pausa e volta
 *
 * SLA: atendidas dentro do tempo da fila ÷ chamadas que entraram. Quem
 * desistiu antes do tempo conta contra — é a conta mais dura, e a que
 * não esconde fila mal dimensionada.
 */
final class RelatorioFilas
{
    /**
     * @param string $de  'Y-m-d H:i:s'
     * @param string $ate 'Y-m-d H:i:s' (exclusivo)
     */
    public function __construct(
        private readonly string $de,
        private readonly string $ate,
        private readonly ?string $fila = null,
        private readonly bool $soCallCenter = false,
    ) {
    }

    /** Período a partir de datas 'Y-m-d' do formulário (fim inclusivo). */
    public static function doPeriodo(string $de, string $ate, ?string $fila = null, bool $soCc = false): self
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $de) ?: new \DateTimeImmutable('today');
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $ate) ?: $d;
        if ($a < $d) {
            [$d, $a] = [$a, $d];
        }
        // No máximo um ano: o queue_log de uma central movimentada é grande.
        if ($d->diff($a)->days > 366) {
            $d = $a->modify('-366 days');
        }

        return new self($d->format('Y-m-d 00:00:00'), $a->modify('+1 day')->format('Y-m-d 00:00:00'),
                        $fila ?: null, $soCc);
    }

    /** @return array{0:string, 1:list<mixed>} filtro de fila sobre queue_log q */
    private function filtro(): array
    {
        $sql = 'q.time >= ? AND q.time < ?';
        $args = [$this->de, $this->ate];
        if ($this->fila !== null) {
            $sql .= ' AND q.queuename = ?';
            $args[] = $this->fila;
        }
        if ($this->soCallCenter) {
            $sql .= ' AND q.queuename IN (SELECT numero FROM filas WHERE callcenter = 1)';
        }

        return [$sql, $args];
    }

    /** @return list<array<string,mixed>> uma linha por fila */
    public function filas(): array
    {
        [$f, $args] = $this->filtro();

        $linhas = Bd::todos(
            "SELECT q.queuename AS numero, fl.nome, COALESCE(fl.sla_segundos, 20) AS sla_segundos,
                    SUM(q.event = 'ENTERQUEUE') AS recebidas,
                    SUM(q.event = 'CONNECT') AS atendidas,
                    SUM(q.event = 'ABANDON') AS abandonadas,
                    SUM(q.event = 'EXITWITHTIMEOUT') AS estouradas,
                    SUM(q.event IN ('EXITEMPTY','JOINEMPTY','JOINUNAVAIL','EXITUNAVAIL')) AS sem_agente,
                    SUM(q.event = 'EXITWITHKEY') AS retorno,
                    SUM(q.event = 'CONNECT' AND CAST(q.data1 AS UNSIGNED) <= COALESCE(fl.sla_segundos, 20)) AS no_sla,
                    AVG(IF(q.event = 'CONNECT', CAST(q.data1 AS UNSIGNED), NULL)) AS tme,
                    MAX(IF(q.event = 'CONNECT', CAST(q.data1 AS UNSIGNED), NULL)) AS maior_espera,
                    AVG(IF(q.event IN ('COMPLETECALLER','COMPLETEAGENT'), CAST(q.data2 AS UNSIGNED), NULL)) AS tma,
                    AVG(IF(q.event = 'ABANDON', CAST(q.data3 AS UNSIGNED), NULL)) AS espera_abandono
               FROM queue_log q LEFT JOIN filas fl ON fl.numero = q.queuename
              WHERE {$f} AND q.queuename NOT IN ('NONE','')
           GROUP BY q.queuename, fl.nome, fl.sla_segundos
           ORDER BY q.queuename",
            $args
        );

        return array_map(static function (array $l): array {
            $recebidas = (int) $l['recebidas'];

            return [
                'numero'          => (string) $l['numero'],
                'nome'            => (string) ($l['nome'] ?? $l['numero']),
                'sla_segundos'    => (int) $l['sla_segundos'],
                'recebidas'       => $recebidas,
                'atendidas'       => (int) $l['atendidas'],
                'abandonadas'     => (int) $l['abandonadas'],
                'estouradas'      => (int) $l['estouradas'],
                'sem_agente'      => (int) $l['sem_agente'],
                'retorno'         => (int) $l['retorno'],
                'no_sla'          => (int) $l['no_sla'],
                'sla'             => $recebidas > 0 ? round((int) $l['no_sla'] / $recebidas * 100, 1) : null,
                'abandono'        => $recebidas > 0 ? round((int) $l['abandonadas'] / $recebidas * 100, 1) : null,
                'tme'             => (int) round((float) $l['tme']),
                'maior_espera'    => (int) $l['maior_espera'],
                'tma'             => (int) round((float) $l['tma']),
                'espera_abandono' => (int) round((float) $l['espera_abandono']),
            ];
        }, $linhas);
    }

    /** Chamadas por hora do dia, somando o período. @return list<array<string,int>> */
    public function porHora(): array
    {
        [$f, $args] = $this->filtro();
        $porHora = array_fill(0, 24, ['recebidas' => 0, 'atendidas' => 0, 'abandonadas' => 0]);

        foreach (Bd::todos(
            "SELECT HOUR(q.time) AS h,
                    SUM(q.event = 'ENTERQUEUE') AS recebidas,
                    SUM(q.event = 'CONNECT') AS atendidas,
                    SUM(q.event = 'ABANDON') AS abandonadas
               FROM queue_log q WHERE {$f} GROUP BY HOUR(q.time)",
            $args
        ) as $l) {
            $porHora[(int) $l['h']] = [
                'recebidas' => (int) $l['recebidas'], 'atendidas' => (int) $l['atendidas'],
                'abandonadas' => (int) $l['abandonadas'],
            ];
        }

        $saida = [];
        foreach ($porHora as $h => $v) {
            $saida[] = ['hora' => $h] + $v;
        }

        return $saida;
    }

    /** Uma linha por dia. @return list<array<string,mixed>> */
    public function porDia(): array
    {
        [$f, $args] = $this->filtro();

        return array_map(static fn (array $l): array => [
            'dia' => (string) $l['dia'],
            'recebidas' => (int) $l['recebidas'],
            'atendidas' => (int) $l['atendidas'],
            'abandonadas' => (int) $l['abandonadas'],
        ], Bd::todos(
            "SELECT DATE(q.time) AS dia,
                    SUM(q.event = 'ENTERQUEUE') AS recebidas,
                    SUM(q.event = 'CONNECT') AS atendidas,
                    SUM(q.event = 'ABANDON') AS abandonadas
               FROM queue_log q WHERE {$f} GROUP BY DATE(q.time) ORDER BY dia",
            $args
        ));
    }

    /**
     * Produtividade por agente: chamadas, conversa, tempo logado e em
     * pausa (por motivo), nota da pesquisa e tabulações.
     *
     * @return list<array<string,mixed>>
     */
    public function agentes(): array
    {
        [$f, $args] = $this->filtro();

        $chamadas = [];
        foreach (Bd::todos(
            "SELECT q.agent,
                    SUM(q.event = 'CONNECT') AS atendidas,
                    SUM(q.event IN ('COMPLETECALLER','COMPLETEAGENT')) AS encerradas,
                    SUM(IF(q.event IN ('COMPLETECALLER','COMPLETEAGENT'), CAST(q.data2 AS UNSIGNED), 0)) AS falado,
                    SUM(q.event = 'COMPLETEAGENT') AS desligou_agente,
                    SUM(q.event = 'RINGNOANSWER') AS nao_atendeu
               FROM queue_log q
              WHERE {$f} AND q.agent NOT IN ('NONE','')
           GROUP BY q.agent",
            $args
        ) as $l) {
            $chamadas[(string) $l['agent']] = $l;
        }

        $tempos = $this->temposDeSessao();

        // A nota da pesquisa é do cliente, e a resposta guarda o uniqueid
        // da chamada — o mesmo callid que o CONNECT do agente tem. Vale o
        // índice de 0 a 100, e não a nota: pesquisas de escalas diferentes,
        // ou em que 10 é o pior, não se somam nota com nota.
        $notas = [];
        foreach (Bd::todos(
            "SELECT q.agent, AVG(p.satisfacao) AS nota, COUNT(p.satisfacao) AS respostas
               FROM pesquisa_respostas p
               JOIN queue_log q ON q.callid = p.uniqueid AND q.event = 'CONNECT'
              WHERE {$f} AND p.satisfacao IS NOT NULL
           GROUP BY q.agent",
            $args
        ) as $n) {
            $notas[(string) $n['agent']] = $n;
        }

        $tabuladas = [];
        foreach (Bd::todos(
            'SELECT agente_id, COUNT(*) AS n FROM cc_atendimentos
              WHERE atendido_em >= ? AND atendido_em < ? AND tabulado_em IS NOT NULL'
            . ($this->fila !== null ? ' AND fila = ?' : '')
            . ' GROUP BY agente_id',
            $this->fila !== null ? [$this->de, $this->ate, $this->fila] : [$this->de, $this->ate]
        ) as $t) {
            $tabuladas[CallCenter::nomeDoMembro((int) $t['agente_id'])] = (int) $t['n'];
        }

        $nomes = [];
        foreach (Bd::todos('SELECT a.id, a.matricula, u.nome FROM cc_agentes a JOIN usuarios u ON u.id = a.usuario_id') as $a) {
            $nomes[CallCenter::nomeDoMembro((int) $a['id'])] = ['nome' => $a['nome'], 'matricula' => $a['matricula']];
        }

        $todos = array_unique(array_merge(array_keys($chamadas), array_keys($tempos)));
        $saida = [];
        foreach ($todos as $agente) {
            $c = $chamadas[$agente] ?? [];
            $t = $tempos[$agente] ?? ['logado' => 0, 'pausado' => 0, 'produtiva' => 0, 'pausas' => []];
            $encerradas = (int) ($c['encerradas'] ?? 0);
            $falado = (int) ($c['falado'] ?? 0);

            $saida[] = [
                'agente'        => $agente,
                'nome'          => $nomes[$agente]['nome'] ?? $agente,
                'matricula'     => $nomes[$agente]['matricula'] ?? null,
                'atendidas'     => (int) ($c['atendidas'] ?? 0),
                'tma'           => $encerradas > 0 ? intdiv($falado, $encerradas) : 0,
                'falado'        => $falado,
                'nao_atendeu'   => (int) ($c['nao_atendeu'] ?? 0),
                'desligou_agente' => (int) ($c['desligou_agente'] ?? 0),
                'logado'        => $t['logado'],
                'pausado'       => $t['pausado'],
                'pausa_produtiva' => $t['produtiva'],
                // Ocupação: quanto do tempo disponível (logado e fora de
                // pausa improdutiva) foi conversa.
                'ocupacao'      => ($disp = $t['logado'] - ($t['pausado'] - $t['produtiva'])) > 0
                    ? round(min(100, $falado / $disp * 100), 1) : null,
                'pausas'        => $t['pausas'],
                'nota'          => isset($notas[$agente]) ? round((float) $notas[$agente]['nota'], 2) : null,
                'respostas'     => (int) ($notas[$agente]['respostas'] ?? 0),
                'tabuladas'     => $tabuladas[$agente] ?? 0,
            ];
        }

        usort($saida, static fn ($a, $b) => $b['atendidas'] <=> $a['atendidas'] ?: strcmp($a['nome'], $b['nome']));

        return $saida;
    }

    /**
     * Tempo logado e em pausa, por agente, dentro do período.
     *
     * O queue_log registra transições, não intervalos: entra (ADDMEMBER),
     * pausa (PAUSE/PAUSEALL), volta, sai. O estado de quem já estava
     * logado antes do período começar é reconstruído a partir do dia
     * anterior, e quem continua logado conta até agora.
     *
     * @return array<string, array{logado:int, pausado:int, produtiva:int, pausas:array<string,int>}>
     */
    public function temposDeSessao(): array
    {
        $produtivos = array_column(
            Bd::todos('SELECT nome, produtiva FROM cc_pausas_motivos'), 'produtiva', 'nome'
        );

        $inicio = strtotime($this->de);
        $fim = min(strtotime($this->ate), time());
        $filtroFila = '';
        $argFila = [];
        if ($this->fila !== null) {
            $filtroFila = ' AND (queuename = ? OR queuename = \'NONE\')';
            $argFila = [$this->fila];
        }

        // O estado de cada agente quando o período começa: a última entrada
        // ou saída, e a última pausa ou volta, de cada fila — de qualquer
        // data. Olhar só um dia para trás perdia quem estava logado desde a
        // semana anterior (o Asterisk guarda o membro entre reinícios), e o
        // relatório de segunda dava zero horas logado a quem passou o fim de
        // semana no atendimento.
        $semente = Bd::todos(
            "SELECT q.id, UNIX_TIMESTAMP(q.time) AS t, q.agent, q.queuename, q.event, q.data1
               FROM queue_log q
               JOIN (SELECT MAX(id) AS id FROM queue_log
                      WHERE time < ? AND agent NOT IN ('NONE','') {$filtroFila}
                        AND event IN ('ADDMEMBER','REMOVEMEMBER')
                   GROUP BY agent, queuename
                  UNION ALL
                     SELECT MAX(id) FROM queue_log
                      WHERE time < ? AND agent NOT IN ('NONE','') {$filtroFila}
                        AND event IN ('PAUSE','UNPAUSE','PAUSEALL','UNPAUSEALL')
                   GROUP BY agent, queuename) u ON u.id = q.id
           ORDER BY q.id",
            [$this->de, ...$argFila, $this->de, ...$argFila]
        );

        $eventos = Bd::todos(
            "SELECT UNIX_TIMESTAMP(time) AS t, agent, queuename, event, data1
               FROM queue_log
              WHERE time >= ? AND time < ? {$filtroFila}
                AND event IN ('ADDMEMBER','REMOVEMEMBER','PAUSE','UNPAUSE','PAUSEALL','UNPAUSEALL')
                AND agent NOT IN ('NONE','')
           ORDER BY time, id",
            [$this->de, $this->ate, ...$argFila]
        );

        // Uma pausa anterior à última entrada ou saída da mesma fila já não
        // vale: quem pausou, saiu e entrou de novo está livre. Sem este corte
        // a semente chegava como "pausa antiga, depois entrada", a entrada
        // não limpa pausa, e o período inteiro contava como almoço.
        $ultimaSessao = [];
        foreach ($semente as $ev) {
            if (in_array($ev['event'], ['ADDMEMBER', 'REMOVEMEMBER'], true)) {
                $ultimaSessao[$ev['agent'] . '|' . $ev['queuename']] = (int) $ev['id'];
            }
        }
        $semente = array_values(array_filter(
            $semente,
            static fn (array $ev): bool => !in_array($ev['event'], ['PAUSE', 'UNPAUSE'], true)
                || (int) $ev['id'] > ($ultimaSessao[$ev['agent'] . '|' . $ev['queuename']] ?? 0)
        ));

        // A semente só monta o estado: nada dela conta tempo, porque tudo
        // aconteceu antes do período. O relógio de cada um começa no início.
        foreach ($semente as &$ev) {
            $ev['t'] = $inicio;
        }
        unset($ev);
        $eventos = [...$semente, ...$eventos];

        /** @var array<string, array{filas: array<string,bool>, pausadas: array<string,bool>, motivo: string, desde: float}> $estado */
        $estado = [];
        $acumulado = [];

        // Soma ao agente o pedaço [desde, ate] que cai dentro do período.
        $somar = static function (string $agente, array $e, float $ate) use (&$acumulado, $inicio, $fim, $produtivos): void {
            $a = max($e['desde'], $inicio);
            $b = min($ate, $fim);
            if ($b <= $a || $e['filas'] === []) {
                return;
            }
            $dur = (int) round($b - $a);
            $acumulado[$agente] ??= ['logado' => 0, 'pausado' => 0, 'produtiva' => 0, 'pausas' => []];
            $acumulado[$agente]['logado'] += $dur;
            if ($e['pausadas'] !== []) {
                $motivo = $e['motivo'] !== '' ? $e['motivo'] : 'Sem motivo';
                $acumulado[$agente]['pausado'] += $dur;
                $acumulado[$agente]['pausas'][$motivo] = ($acumulado[$agente]['pausas'][$motivo] ?? 0) + $dur;
                if ((int) ($produtivos[$motivo] ?? 0) === 1) {
                    $acumulado[$agente]['produtiva'] += $dur;
                }
            }
        };

        foreach ($eventos as $ev) {
            $agente = (string) $ev['agent'];
            $t = (float) $ev['t'];
            $e = $estado[$agente] ?? ['filas' => [], 'pausadas' => [], 'motivo' => '', 'desde' => $t];

            $somar($agente, $e, $t);
            $e['desde'] = $t;
            $fila = (string) $ev['queuename'];

            switch ($ev['event']) {
                case 'ADDMEMBER':
                    $e['filas'][$fila] = true;
                    break;
                case 'REMOVEMEMBER':
                    unset($e['filas'][$fila], $e['pausadas'][$fila]);
                    break;
                case 'PAUSE':
                    $e['pausadas'][$fila] = true;
                    $e['motivo'] = (string) $ev['data1'];
                    break;
                case 'PAUSEALL':
                    foreach ($e['filas'] as $q => $_) {
                        $e['pausadas'][$q] = true;
                    }
                    $e['motivo'] = (string) $ev['data1'];
                    break;
                case 'UNPAUSE':
                    unset($e['pausadas'][$fila]);
                    break;
                case 'UNPAUSEALL':
                    $e['pausadas'] = [];
                    break;
            }
            if ($e['pausadas'] === []) {
                $e['motivo'] = '';
            }
            $estado[$agente] = $e;
        }

        foreach ($estado as $agente => $e) {
            $somar($agente, $e, (float) $fim);
        }

        return $acumulado;
    }

    /** Quantas vezes cada tabulação foi usada, por fila. @return list<array<string,mixed>> */
    public function tabulacoes(): array
    {
        return Bd::todos(
            'SELECT a.fila, t.nome, t.grupo, COUNT(*) AS n
               FROM cc_atendimentos a JOIN cc_tabulacoes t ON t.id = a.tabulacao_id
              WHERE a.atendido_em >= ? AND a.atendido_em < ?'
            . ($this->fila !== null ? ' AND a.fila = ?' : '')
            . ' GROUP BY a.fila, t.nome, t.grupo ORDER BY n DESC',
            $this->fila !== null ? [$this->de, $this->ate, $this->fila] : [$this->de, $this->ate]
        );
    }
}
