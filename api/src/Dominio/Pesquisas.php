<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Pesquisa de satisfação: o que o cadastro não pode aceitar e as contas do
 * relatório.
 *
 * A escala é de 0 a 10, no sentido que o cliente escolher: há empresa em
 * que 10 é "muito satisfeito" e há a que usa 10 para "muito insatisfeito".
 * Por isso o relatório não compara nota com nota: cada resposta guarda um
 * índice de 0 (pior) a 100 (melhor), já no sentido certo, e é ele que diz
 * quem está satisfeito. A média da nota só aparece na escala da pesquisa.
 */
final class Pesquisas
{
    /** Índice a partir do qual a resposta conta como satisfeita (1 a 5: 4 e 5). */
    public const SATISFEITO = 70.0;
    /** Índice até o qual conta como insatisfeita (1 a 5: 1 e 2). */
    public const INSATISFEITO = 30.0;

    public const STATUS = ['respondida', 'sem_resposta', 'invalida', 'desligou'];

    /**
     * Última palavra do cadastro, chamada pelo Recurso.
     *
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    public static function conferir(string $acao, array $dados, array $atual): ?array
    {
        if ($acao === 'excluir') {
            $filas = Bd::todos('SELECT numero FROM filas WHERE pesquisa_id = ?', [(int) $atual['id']]);
            if ($filas !== []) {
                return ['mensagem' => 'Esta pesquisa está nas filas ' . implode(', ', array_column($filas, 'numero'))
                                    . '. Tire dela antes de excluir, ou desative a pesquisa.', 'codigo' => 409];
            }

            return null;
        }

        $min = (int) ($dados['nota_min'] ?? $atual['nota_min'] ?? 1);
        $max = (int) ($dados['nota_max'] ?? $atual['nota_max'] ?? 5);
        if ($min >= $max) {
            return ['mensagem' => 'A nota mínima precisa ser menor que a máxima.', 'campo' => 'nota_max', 'codigo' => 422];
        }

        $numero = trim((string) ($dados['numero'] ?? ''));
        if ($numero !== '' && ($ocupado = self::numeroOcupado($numero, (int) ($atual['id'] ?? 0))) !== null) {
            return ['mensagem' => "O número {$numero} já é {$ocupado}.", 'campo' => 'numero', 'codigo' => 409];
        }

        return null;
    }

    /** O que já atende por este número, ou null. */
    private static function numeroOcupado(string $numero, int $proprio): ?string
    {
        foreach ([
            ['SELECT 1 FROM ramais WHERE numero = ?', 'um ramal'],
            ['SELECT 1 FROM filas WHERE numero = ?', 'uma fila'],
            ['SELECT 1 FROM grupos_toque WHERE numero = ?', 'um grupo de toque'],
            ['SELECT 1 FROM conferencias WHERE numero = ?', 'uma sala de conferência'],
            ['SELECT 1 FROM grupos_paging WHERE numero = ?', 'um grupo de megafonia'],
            ['SELECT 1 FROM estacionamentos WHERE numero_estacionar = ? AND ativo = 1', 'o número de estacionar'],
        ] as [$sql, $oque]) {
            if (Bd::valor($sql, [$numero]) !== false) {
                return $oque;
            }
        }
        if (Bd::valor('SELECT 1 FROM pesquisas WHERE numero = ? AND id <> ?', [$numero, $proprio]) !== false) {
            return 'de outra pesquisa';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Relatório
    // ------------------------------------------------------------------

    /**
     * O WHERE comum a todas as visões do relatório.
     *
     * @param  array<string,mixed> $f pesquisa, de, ate, fila, ramal, status
     * @return array{0:string, 1:list<mixed>}
     */
    public static function filtro(array $f): array
    {
        $onde = ['r.pesquisa_id = ?', 'r.criado_em >= ?', 'r.criado_em <= ?'];
        $args = [(int) $f['pesquisa'], $f['de'] . ' 00:00:00', $f['ate'] . ' 23:59:59'];
        if (($f['fila'] ?? '') !== '') {
            $onde[] = 'r.fila = ?';
            $args[] = (string) $f['fila'];
        }
        if (($f['ramal'] ?? '') !== '') {
            // O atendente é o ramal, ou o agente do call center ("Agente/5").
            $onde[] = '(r.ramal = ? OR r.agente = ?)';
            $args[] = (string) $f['ramal'];
            $args[] = (string) $f['ramal'];
        }
        if (in_array($f['status'] ?? '', self::STATUS, true)) {
            $onde[] = 'r.status = ?';
            $args[] = (string) $f['status'];
        }

        return [' WHERE ' . implode(' AND ', $onde), $args];
    }

    /** Colunas de contagem e índice que todas as visões repetem. */
    private static function medidas(): string
    {
        $s = self::SATISFEITO;
        $i = self::INSATISFEITO;

        return "COUNT(*) AS ofertadas,
                SUM(r.status = 'respondida') AS respondidas,
                ROUND(AVG(r.satisfacao), 1) AS satisfacao,
                SUM(r.satisfacao >= {$s}) AS satisfeitos,
                SUM(r.satisfacao <= {$i}) AS insatisfeitos";
    }

    /** @param array<string,mixed> $f */
    public static function resultados(array $f): array
    {
        $p = Bd::um('SELECT id, nome, nota_min, nota_max, sentido FROM pesquisas WHERE id = ?', [(int) $f['pesquisa']]);
        if ($p === null) {
            return [];
        }
        [$onde, $args] = self::filtro($f);
        $min = (int) $p['nota_min'];
        $max = (int) $p['nota_max'];

        $r = Bd::um(
            'SELECT ' . self::medidas() . ",
                    SUM(r.status = 'sem_resposta') AS sem_resposta,
                    SUM(r.status = 'invalida') AS invalidas,
                    SUM(r.status = 'desligou') AS desligaram,
                    -- A média da nota só tem sentido na escala atual.
                    ROUND(AVG(IF(r.nota_min = ? AND r.nota_max = ? AND r.sentido = ?, r.nota, NULL)), 2) AS media_nota,
                    SUM(r.status = 'respondida' AND NOT (r.nota_min <=> ? AND r.nota_max <=> ? AND r.sentido <=> ?)) AS outra_escala
               FROM pesquisa_respostas r {$onde}",
            [$min, $max, $p['sentido'], $min, $max, $p['sentido'], ...$args]
        ) ?? [];

        $ofertadas = (int) ($r['ofertadas'] ?? 0);
        $respondidas = (int) ($r['respondidas'] ?? 0);
        $pct = static fn (int $n, int $de): ?float => $de > 0 ? round(100 * $n / $de, 1) : null;

        // Toda nota da escala aparece, inclusive a que ninguém deu: uma
        // barra que some parece nota que não existe.
        $contagem = array_column(Bd::todos(
            "SELECT r.nota, COUNT(*) AS n FROM pesquisa_respostas r {$onde}
                AND r.status = 'respondida' AND r.nota_min = ? AND r.nota_max = ? AND r.sentido = ?
           GROUP BY r.nota",
            [...$args, $min, $max, $p['sentido']]
        ), 'n', 'nota');
        $porNota = [];
        for ($n = $min; $n <= $max; $n++) {
            $porNota[] = ['nota' => $n, 'quantidade' => (int) ($contagem[$n] ?? 0),
                          'satisfacao' => round(100 * ($p['sentido'] === 'menor_melhor' ? $max - $n : $n - $min) / ($max - $min), 1)];
        }

        $numeros = static function (array $l): array {
            foreach (['ofertadas', 'respondidas', 'satisfeitos', 'insatisfeitos'] as $c) {
                $l[$c] = (int) ($l[$c] ?? 0);
            }
            $l['satisfacao'] = $l['satisfacao'] !== null ? (float) $l['satisfacao'] : null;
            if (array_key_exists('media_nota', $l)) {
                $l['media_nota'] = $l['media_nota'] !== null ? (float) $l['media_nota'] : null;
            }

            return $l;
        };

        $porFila = array_map($numeros, Bd::todos(
            'SELECT r.fila, f.nome, ' . self::medidas() . ",
                    ROUND(AVG(IF(r.nota_min = ? AND r.nota_max = ? AND r.sentido = ?, r.nota, NULL)), 2) AS media_nota
               FROM pesquisa_respostas r LEFT JOIN filas f ON f.numero = r.fila {$onde}
           GROUP BY r.fila, f.nome ORDER BY respondidas DESC, r.fila",
            [$min, $max, $p['sentido'], ...$args]
        ));

        // O atendente é o agente do call center quando houver, senão o ramal.
        $porAtendente = array_map($numeros, Bd::todos(
            'SELECT COALESCE(r.agente, r.ramal) AS atendente, MAX(r.ramal) AS ramal,
                    COALESCE(MAX(ua.nome), MAX(ra.nome)) AS nome, ' . self::medidas() . ",
                    ROUND(AVG(IF(r.nota_min = ? AND r.nota_max = ? AND r.sentido = ?, r.nota, NULL)), 2) AS media_nota
               FROM pesquisa_respostas r
          LEFT JOIN cc_agentes ca ON r.agente LIKE 'Agente/%' AND ca.id = CAST(SUBSTRING(r.agente, 8) AS UNSIGNED)
          LEFT JOIN usuarios ua ON ua.id = ca.usuario_id
          LEFT JOIN ramais ra ON ra.numero = r.ramal
               {$onde} AND COALESCE(r.agente, r.ramal) IS NOT NULL
           GROUP BY COALESCE(r.agente, r.ramal)
           ORDER BY satisfacao DESC, respondidas DESC",
            [$min, $max, $p['sentido'], ...$args]
        ));

        $porDia = array_map($numeros, Bd::todos(
            'SELECT DATE(r.criado_em) AS dia, ' . self::medidas() . "
               FROM pesquisa_respostas r {$onde}
           GROUP BY DATE(r.criado_em) ORDER BY dia",
            $args
        ));

        return [
            'pesquisa' => ['id' => (int) $p['id'], 'nome' => $p['nome'], 'nota_min' => $min,
                           'nota_max' => $max, 'sentido' => $p['sentido']],
            'faixas' => ['satisfeito' => self::SATISFEITO, 'insatisfeito' => self::INSATISFEITO],
            'resumo' => [
                'ofertadas' => $ofertadas,
                'respondidas' => $respondidas,
                'sem_resposta' => (int) ($r['sem_resposta'] ?? 0),
                'invalidas' => (int) ($r['invalidas'] ?? 0),
                'desligaram' => (int) ($r['desligaram'] ?? 0),
                'taxa_resposta' => $pct($respondidas, $ofertadas),
                'satisfacao' => $r['satisfacao'] !== null ? (float) $r['satisfacao'] : null,
                'media_nota' => $r['media_nota'] !== null ? (float) $r['media_nota'] : null,
                'satisfeitos_pct' => $pct((int) ($r['satisfeitos'] ?? 0), $respondidas),
                'insatisfeitos_pct' => $pct((int) ($r['insatisfeitos'] ?? 0), $respondidas),
                'neutros_pct' => $respondidas > 0
                    ? round(100 - 100 * ((int) $r['satisfeitos'] + (int) $r['insatisfeitos']) / $respondidas, 1) : null,
                'outra_escala' => (int) ($r['outra_escala'] ?? 0),
            ],
            'por_nota' => $porNota,
            'por_fila' => $porFila,
            'por_atendente' => $porAtendente,
            'por_dia' => $porDia,
        ];
    }

    /**
     * As respostas, uma a uma, com o nome de quem atendeu.
     *
     * @param  array<string,mixed> $f
     * @return array{dados: list<array<string,mixed>>, total:int}
     */
    public static function respostas(array $f, int $limite, int $deslocamento): array
    {
        [$onde, $args] = self::filtro($f);
        $total = (int) Bd::valor("SELECT COUNT(*) FROM pesquisa_respostas r {$onde}", $args);

        $dados = Bd::todos(
            "SELECT r.id, r.criado_em, r.origem, r.fila, f.nome AS fila_nome, r.ramal, r.agente,
                    COALESCE(ua.nome, ra.nome) AS atendente, r.status, r.nota, r.nota_min, r.nota_max,
                    r.sentido, r.satisfacao, r.uniqueid
               FROM pesquisa_respostas r
          LEFT JOIN filas f ON f.numero = r.fila
          LEFT JOIN cc_agentes ca ON r.agente LIKE 'Agente/%' AND ca.id = CAST(SUBSTRING(r.agente, 8) AS UNSIGNED)
          LEFT JOIN usuarios ua ON ua.id = ca.usuario_id
          LEFT JOIN ramais ra ON ra.numero = r.ramal
               {$onde}
           ORDER BY r.criado_em DESC, r.id DESC
              LIMIT {$limite} OFFSET {$deslocamento}",
            $args
        );
        foreach ($dados as &$d) {
            $d['nota'] = $d['nota'] !== null ? (int) $d['nota'] : null;
            $d['satisfacao'] = $d['satisfacao'] !== null ? (float) $d['satisfacao'] : null;
            $d['avaliacao'] = $d['satisfacao'] === null ? null
                : ($d['satisfacao'] >= self::SATISFEITO ? 'satisfeito'
                    : ($d['satisfacao'] <= self::INSATISFEITO ? 'insatisfeito' : 'neutro'));
        }
        unset($d);

        return ['dados' => $dados, 'total' => $total];
    }
}
