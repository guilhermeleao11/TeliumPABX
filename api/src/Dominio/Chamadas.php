<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Uma ligação é uma ligação: a contagem que todos os painéis usam.
 *
 * O CDR do Asterisk não tem uma linha por ligação. Ele tem uma por
 * trecho: a fila que toca dois agentes grava uma linha para cada um (e
 * mais uma quando o cliente segue para a pesquisa), a saída que dá
 * ocupado grava duas, a transferência grava uma por perna. Contar linhas
 * dava, numa bancada com 10 ligações, 16 "chamadas", e a fila mostrava
 * como perdida a perna do agente que não atendeu porque o colega atendeu.
 *
 * Aqui os trechos se juntam pelo linkedid, que o Asterisk dá igual a
 * todos os canais da mesma ligação. Cada ligação sai uma vez, com:
 *
 *   direcao     entrada (veio de um tronco), saida (foi para um tronco) ou
 *               interna. Entrada que saiu de novo (siga-me para o
 *               celular) é entrada: quem ligou foi o cliente.
 *   atendida    alguém atendeu: um trecho ANSWERED com canal de destino.
 *               O trecho ANSWERED sem destino é a central (URA, fila,
 *               pesquisa, caixa postal) e não conta como atendimento.
 *   falado      a conversa, em segundos. No trecho de fila o CDR conta
 *               a espera junto, e a conversa vem do registro da fila.
 *   ramal_origem  o ramal que fez a ligação (o canal, não o "src": no
 *               click to call o src é o número do cliente).
 *   atenderam   os ramais que atenderam.
 *   tocaram     os ramais em que tocou direto. Oferta de fila não entra:
 *               numa fila com três agentes a ligação tocou em três e é
 *               UMA ligação da fila, não três recebidas dos ramais.
 */
final class Chamadas
{
    /** Trechos da mesma ligação podem cair antes do início ou depois do fim do período. */
    private const MARGEM_HORAS = 12;

    /** O ramal (ou o tronco) de um canal: PJSIP/1001-0000002a e Local/1001@ctx-…;1 dão 1001. */
    private static function ramalSql(string $coluna): string
    {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX({$coluna}, '-', 1), '/', -1), '@', 1)";
    }

    /**
     * O SELECT de uma linha por ligação. Leva quatro parâmetros: o início e
     * o fim (exclusivo) da janela, duas vezes — uma com margem, para juntar
     * os trechos, e outra sem, para dizer de que período a ligação é (o do
     * primeiro trecho).
     *
     * @param list<string> $condicoes HAVING extras
     */
    private static function sql(array $condicoes = [], string $ordem = '', string $limite = ''): string
    {
        $atendido = "(c.disposition = 'ANSWERED' AND c.dstchannel <> '')";
        $origem = self::ramalSql('c.channel');
        $destino = self::ramalSql('c.dstchannel');
        $having = array_merge(['inicio >= ?', 'inicio < ?'], $condicoes);

        return "SELECT IF(c.linkedid <> '', c.linkedid, c.uniqueid) AS chave,
                       MIN(c.calldate) AS inicio,
                       TIMESTAMPDIFF(SECOND, MIN(c.calldate), MAX(c.calldate + INTERVAL c.duration SECOND)) AS duracao,
                       CASE WHEN SUM(c.direcao = 'entrada') > 0 THEN 'entrada'
                            WHEN SUM(c.direcao = 'saida') > 0 THEN 'saida'
                            ELSE 'interna' END AS direcao,
                       MAX({$atendido}) AS atendida,
                       CASE WHEN MAX({$atendido}) THEN 'ANSWERED'
                            WHEN SUM(c.direcao = 'entrada') > 0 THEN 'NO ANSWER'
                            WHEN MAX(c.disposition = 'BUSY') THEN 'BUSY'
                            WHEN MAX(c.disposition = 'CONGESTION') THEN 'CONGESTION'
                            WHEN MAX(c.disposition = 'FAILED') THEN 'FAILED'
                            ELSE 'NO ANSWER' END AS resultado,
                       SUBSTRING_INDEX(GROUP_CONCAT(c.src ORDER BY c.calldate, c.id SEPARATOR '|'), '|', 1) AS src,
                       SUBSTRING_INDEX(GROUP_CONCAT(c.clid ORDER BY c.calldate, c.id SEPARATOR '\\n'), '\\n', 1) AS clid,
                       SUBSTRING_INDEX(GROUP_CONCAT(c.dst ORDER BY c.calldate, c.id SEPARATOR '|'), '|', 1) AS dst,
                       SUBSTRING_INDEX(GROUP_CONCAT(IF(c.direcao = 'saida', c.dst, NULL) ORDER BY c.calldate, c.id SEPARATOR '|'), '|', 1) AS dst_saida,
                       SUBSTRING_INDEX(GROUP_CONCAT({$origem} ORDER BY c.calldate, c.id SEPARATOR '|'), '|', 1) AS canal_origem,
                       MAX(NULLIF(c.did, '')) AS did,
                       GROUP_CONCAT(DISTINCT NULLIF(c.tronco, '') SEPARATOR '|') AS troncos,
                       GROUP_CONCAT(DISTINCT NULLIF(c.fila, '') SEPARATOR '|') AS filas,
                       MAX(NULLIF(c.gravacao, '')) AS gravacao,
                       MAX(NULLIF(c.motivo, '')) AS motivo,
                       SUM(c.custo) AS custo,
                       COUNT(*) AS trechos,
                       GROUP_CONCAT(CONCAT_WS(',', {$origem}, {$destino}, c.disposition, c.billsec,
                                              c.lastapp, c.uniqueid, c.dstchannel <> '')
                                    ORDER BY c.calldate, c.id SEPARATOR ';') AS pernas
                  FROM cdr c
                 WHERE c.calldate >= ? - INTERVAL " . self::MARGEM_HORAS . " HOUR
                   AND c.calldate < ? + INTERVAL " . self::MARGEM_HORAS . " HOUR
              GROUP BY chave
                HAVING " . implode(' AND ', $having) . "
                {$ordem} {$limite}";
    }

    /**
     * Os filtros que a tela de ligações oferece, como HAVING.
     *
     * @param array<string,mixed> $f direcao, resultado, q (número ou ramal), ramal, tronco
     * @return array{0:list<string>, 1:list<mixed>}
     */
    private static function filtros(array $f): array
    {
        $cond = [];
        $args = [];
        if (in_array($f['direcao'] ?? '', ['entrada', 'saida', 'interna'], true)) {
            $cond[] = 'direcao = ?';
            $args[] = $f['direcao'];
        }
        if (in_array($f['resultado'] ?? '', ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'], true)) {
            $cond[] = 'resultado = ?';
            $args[] = $f['resultado'];
        }
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $termo = '%' . addcslashes(trim((string) $f['q']), '%_\\') . '%';
            $cond[] = 'SUM(c.src LIKE ? OR c.dst LIKE ? OR c.clid LIKE ? OR c.channel LIKE ? OR c.dstchannel LIKE ?) > 0';
            array_push($args, $termo, $termo, $termo, $termo, $termo);
        }
        if (trim((string) ($f['ramal'] ?? '')) !== '') {
            $cond[] = 'SUM(' . self::ramalSql('c.channel') . ' = ? OR ' . self::ramalSql('c.dstchannel') . ' = ?) > 0';
            array_push($args, (string) $f['ramal'], (string) $f['ramal']);
        }
        if (trim((string) ($f['participou'] ?? '')) !== '') {
            // O que a ligação foi para o ramal (papel()): ele fez, atendeu,
            // ou tocou nele direto. A oferta de fila que outro atendeu não.
            $cond[] = "((canal_origem = ? AND direcao <> 'entrada') OR SUM(" . self::ramalSql('c.dstchannel')
                    . " = ? AND (c.lastapp <> 'Queue' OR (c.disposition = 'ANSWERED' AND c.dstchannel <> ''))) > 0)";
            array_push($args, (string) $f['participou'], (string) $f['participou']);
        }
        if (trim((string) ($f['tronco'] ?? '')) !== '') {
            $cond[] = 'SUM(c.tronco = ?) > 0';
            $args[] = (string) $f['tronco'];
        }

        return [$cond, $args];
    }

    /** @return list<mixed> os parâmetros da janela, na ordem do SELECT */
    private static function janela(string $de, string $ate): array
    {
        return [$de, $ate, $de, $ate];
    }

    /** 'Y-m-d' do formulário, conferido. */
    public static function dia(mixed $v): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);

        return $d !== false && $d->format('Y-m-d') === (string) $v ? (string) $v : null;
    }

    /** @return array<string,true> todos os números de ramal, inclusive os desativados: o histórico é deles */
    public static function ramais(): array
    {
        return array_fill_keys(array_map('strval', array_column(Bd::todos('SELECT numero FROM ramais'), 'numero')), true);
    }

    /**
     * Uma página de ligações, a mais recente primeiro (a tela do CDR).
     *
     * @param array<string,mixed> $f
     * @return array{dados: list<array<string,mixed>>, total:int}
     */
    public static function pagina(string $de, string $ate, array $f, int $limite, int $deslocamento): array
    {
        [$cond, $args] = self::filtros($f);
        $inicio = $de . ' 00:00:00';
        $fim = (new \DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d 00:00:00');
        $janela = self::janela($inicio, $fim);

        Bd::executar('SET SESSION group_concat_max_len = 1048576');
        $total = (int) Bd::valor('SELECT COUNT(*) FROM (' . self::sql($cond) . ') t', [...$janela, ...$args]);
        $linhas = Bd::todos(self::sql($cond, 'ORDER BY inicio DESC, chave DESC',
                                      'LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $deslocamento)),
                            [...$janela, ...$args]);

        $ramais = self::ramais();
        $falas = self::conversasDeFila($inicio, $fim);

        return ['dados' => array_map(static fn (array $l): array => self::montar($l, $ramais, $falas), $linhas),
                'total' => $total];
    }

    /** Quantas ligações o período tem. @param array<string,mixed> $f */
    public static function contar(string $de, string $ate, array $f = []): int
    {
        [$cond, $args] = self::filtros($f);
        $fim = (new \DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d 00:00:00');
        Bd::executar('SET SESSION group_concat_max_len = 1048576');

        return (int) Bd::valor('SELECT COUNT(*) FROM (' . self::sql($cond) . ') t',
                               [...self::janela($de . ' 00:00:00', $fim), ...$args]);
    }

    /**
     * Todas as ligações do período, um dia por vez: um mês de central
     * grande não cabe inteiro na memória, um dia cabe.
     *
     * @param array<string,mixed> $f
     * @return \Generator<int, array<string,mixed>>
     */
    public static function percorrer(string $de, string $ate, array $f = []): \Generator
    {
        [$cond, $args] = self::filtros($f);
        $ramais = self::ramais();
        Bd::executar('SET SESSION group_concat_max_len = 1048576');

        $dia = new \DateTimeImmutable($de);
        $ultimo = new \DateTimeImmutable($ate);
        while ($dia <= $ultimo) {
            $inicio = $dia->format('Y-m-d 00:00:00');
            $fim = $dia->modify('+1 day')->format('Y-m-d 00:00:00');
            $falas = self::conversasDeFila($inicio, $fim);
            foreach (Bd::todos(self::sql($cond, 'ORDER BY inicio'), [...self::janela($inicio, $fim), ...$args]) as $l) {
                yield self::montar($l, $ramais, $falas);
            }
            $dia = $dia->modify('+1 day');
        }
    }

    /** Os trechos de uma ligação, como o Asterisk os gravou. @return list<array<string,mixed>> */
    public static function trechos(string $chave): array
    {
        return Bd::todos(
            'SELECT id, calldate, clid, src, dst, channel, dstchannel, lastapp, direcao, duration, billsec,
                    disposition, tronco, motivo, fila, gravacao, custo
               FROM cdr WHERE linkedid = ? OR (linkedid = \'\' AND uniqueid = ?)
           ORDER BY calldate, id',
            [$chave, $chave]
        );
    }

    /**
     * A conversa de cada ligação de fila, pelo registro da fila: o trecho
     * do CDR mede do começo da ligação, com a espera junto.
     *
     * @return array<string,int> callid (uniqueid do cliente) => segundos
     */
    private static function conversasDeFila(string $de, string $ate): array
    {
        $falas = [];
        foreach (Bd::todos(
            "SELECT callid,
                    SUM(CASE WHEN event IN ('COMPLETECALLER','COMPLETEAGENT') THEN CAST(data2 AS UNSIGNED)
                             WHEN event IN ('BLINDTRANSFER','ATTENDEDTRANSFER') THEN CAST(data4 AS UNSIGNED)
                             ELSE 0 END) AS falado
               FROM queue_log
              WHERE time >= ? - INTERVAL " . self::MARGEM_HORAS . " HOUR
                AND time < ? + INTERVAL " . (self::MARGEM_HORAS * 2) . " HOUR
                AND event IN ('COMPLETECALLER','COMPLETEAGENT','BLINDTRANSFER','ATTENDEDTRANSFER')
           GROUP BY callid",
            [$de, $ate]
        ) as $l) {
            $falas[(string) $l['callid']] = (int) $l['falado'];
        }

        return $falas;
    }

    /**
     * Uma linha do SELECT vira a ligação.
     *
     * @param array<string,mixed> $l
     * @param array<string,true>  $ramais
     * @param array<string,int>   $falas
     * @return array<string,mixed>
     */
    public static function montar(array $l, array $ramais, array $falas = []): array
    {
        $ehRamal = static fn (string $r): bool => $r !== '' && isset($ramais[$r]);

        $atenderam = [];
        $tocaram = [];
        $faladoRamal = [];
        $falado = 0;
        $filaUsada = [];
        foreach (explode(';', (string) $l['pernas']) as $p) {
            $c = explode(',', $p);
            if (count($c) < 7) {
                continue;
            }
            [$de, $para, $disp, $seg, $app, $uid, $temDestino] = $c;
            $atendeu = $disp === 'ANSWERED' && $temDestino === '1';
            $seg = (int) $seg;
            if ($atendeu && $app === 'Queue' && isset($falas[$uid]) && !isset($filaUsada[$uid])) {
                // Uma conversa de fila por cliente: o registro da fila já
                // somou as transferências dele.
                $seg = $falas[$uid];
                $filaUsada[$uid] = true;
            } elseif ($atendeu && $app === 'Queue' && isset($filaUsada[$uid])) {
                $seg = 0;
            }
            if ($atendeu) {
                $falado += $seg;
                foreach ([$de, $para] as $r) {
                    if ($ehRamal($r)) {
                        $faladoRamal[$r] = ($faladoRamal[$r] ?? 0) + $seg;
                    }
                }
                if ($ehRamal($para)) {
                    $atenderam[$para] = true;
                }
            }
            if ($ehRamal($para) && ($app !== 'Queue' || $atendeu)) {
                $tocaram[$para] = true;
            }
        }

        $direcao = (string) $l['direcao'];
        $ramalOrigem = $direcao !== 'entrada' && $ehRamal((string) $l['canal_origem']) ? (string) $l['canal_origem'] : null;
        // Ninguém "recebe" a ligação que ele mesmo fez (o click to call toca
        // primeiro no próprio ramal).
        if ($ramalOrigem !== null) {
            unset($tocaram[$ramalOrigem], $atenderam[$ramalOrigem]);
        }

        $origem = $ramalOrigem ?? (string) $l['src'];
        $destino = match ($direcao) {
            'entrada' => (string) ($l['did'] ?? '') !== '' ? (string) $l['did'] : (string) $l['dst'],
            'saida'   => (string) ($l['dst_saida'] ?? '') !== '' ? (string) $l['dst_saida'] : (string) $l['dst'],
            default   => (string) $l['dst'],
        };

        return [
            'chave'        => (string) $l['chave'],
            'inicio'       => (string) $l['inicio'],
            'dia'          => substr((string) $l['inicio'], 0, 10),
            'hora'         => (int) substr((string) $l['inicio'], 11, 2),
            'direcao'      => $direcao,
            'resultado'    => (string) $l['resultado'],
            'atendida'     => (int) $l['atendida'] === 1,
            'falado'       => $falado,
            'duracao'      => max(0, (int) $l['duracao']),
            'origem'       => $origem,
            'destino'      => $destino,
            'clid'         => (string) $l['clid'],
            // O nome de quem ligou, quando o tronco manda um: '"Fulano" <1199…>'.
            'nome'         => (preg_match('/^"?([^"<]*)"?\s*</', (string) $l['clid'], $m) === 1
                               && trim($m[1]) !== '' && trim($m[1]) !== (string) $l['src']) ? trim($m[1]) : null,
            'ramal_origem' => $ramalOrigem,
            'atenderam'    => array_map('strval', array_keys($atenderam)),
            'tocaram'      => array_map('strval', array_keys($tocaram)),
            'falado_ramal' => $faladoRamal,
            'troncos'      => self::lista($l['troncos'] ?? null),
            'filas'        => self::lista($l['filas'] ?? null),
            'gravacao'     => $l['gravacao'] !== null && $l['gravacao'] !== '' ? (string) $l['gravacao'] : null,
            'motivo'       => $l['motivo'] !== null && $l['motivo'] !== '' ? (string) $l['motivo'] : null,
            'custo'        => $l['custo'] !== null ? round((float) $l['custo'], 4) : null,
            'trechos'      => (int) $l['trechos'],
        ];
    }

    /** @return list<string> */
    private static function lista(mixed $texto): array
    {
        return $texto === null || $texto === '' ? [] : array_values(array_filter(explode('|', (string) $texto), 'strlen'));
    }

    /**
     * O que a ligação foi para um ramal: 'feita', 'recebida' ou null (não
     * passou por ele). Interna entre dois ramais é feita para um e recebida
     * para o outro.
     *
     * @param array<string,mixed> $c de montar()
     */
    public static function papel(array $c, string $ramal): ?string
    {
        if ($c['ramal_origem'] === $ramal) {
            return 'feita';
        }
        if (in_array($ramal, $c['tocaram'], true) || in_array($ramal, $c['atenderam'], true)) {
            return 'recebida';
        }

        return null;
    }

    /** Texto do resultado, como as telas escrevem. */
    public static function rotulo(string $resultado): string
    {
        return match ($resultado) {
            'ANSWERED'   => 'Atendida',
            'BUSY'       => 'Ocupado',
            'FAILED'     => 'Falhou',
            'CONGESTION' => 'Sem linha',
            default      => 'Não atendida',
        };
    }
}
