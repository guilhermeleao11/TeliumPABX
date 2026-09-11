<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Confere o cadastro antes de ele virar dialplan.
 *
 * Destinos são polimórficos — uma rota aponta para "fila 3000" por
 * número, não por chave estrangeira —, então o banco não consegue
 * impedir que o alvo suma. O gerador até escreve um NoOp dizendo que o
 * destino não existe, mas isso só aparece no console do Asterisk, para
 * quem for ler. Aqui os mesmos problemas sobem para a tela de quem
 * aplica a configuração.
 */
final class Conferencia
{
    /** @return list<array{nivel:string, onde:string, texto:string}> */
    public static function problemas(): array
    {
        $p = [];
        $ramais = self::coluna('SELECT numero FROM ramais WHERE ativo = 1', 'numero');
        $filas  = self::coluna('SELECT numero FROM filas WHERE ativo = 1', 'numero');
        $uras   = self::coluna('SELECT id FROM ura WHERE ativo = 1', 'id');
        $grupos = self::coluna('SELECT numero FROM grupos_toque WHERE ativo = 1', 'numero');
        $anun   = self::coluna('SELECT id FROM anuncios WHERE ativo = 1', 'id');
        $cond   = self::coluna('SELECT id FROM condicoes_horarias WHERE ativo = 1', 'id');

        $existe = static function (?string $tipo, ?string $valor) use ($ramais, $filas, $uras, $grupos, $anun, $cond): bool {
            $valor = (string) $valor;

            return match ($tipo) {
                'ramal'     => in_array($valor, $ramais, true),
                'fila'      => in_array($valor, $filas, true),
                'ura'       => in_array($valor, $uras, true),
                'grupo'     => in_array($valor, $grupos, true),
                'anuncio'   => in_array($valor, $anun, true),
                'condicao'  => in_array($valor, $cond, true),
                'voicemail' => in_array($valor, $ramais, true),
                default     => true,   // externo, personalizado, desligar
            };
        };

        // Cada linha é "de onde sai" e "para onde aponta".
        $alvos = [
            ['SELECT did AS quem, descricao, destino_tipo AS t, destino_valor AS v
                FROM rotas_entrada WHERE ativo = 1', 'rota de entrada %s'],
            ['SELECT numero AS quem, nome AS descricao, destino_estouro_tipo AS t,
                     destino_estouro_valor AS v FROM filas WHERE ativo = 1',
             'destino de estouro da fila %s'],
            ['SELECT numero AS quem, nome AS descricao, destino_vazia_tipo AS t,
                     destino_vazia_valor AS v FROM filas WHERE ativo = 1',
             'destino de fila vazia da fila %s'],
            ['SELECT numero AS quem, nome AS descricao, destino_cheia_tipo AS t,
                     destino_cheia_valor AS v FROM filas WHERE ativo = 1',
             'destino de fila cheia da fila %s'],
            ['SELECT u.id AS quem, u.nome AS descricao, o.destino_tipo AS t, o.destino_valor AS v
                FROM ura_opcoes o JOIN ura u ON u.id = o.ura_id WHERE u.ativo = 1',
             'opção da URA %s'],
            ['SELECT numero AS quem, nome AS descricao, destino_falha_tipo AS t,
                     destino_falha_valor AS v FROM grupos_toque WHERE ativo = 1',
             'destino de falha do grupo %s'],
        ];

        foreach ($alvos as [$sql, $onde]) {
            foreach (self::consulta($sql) as $linha) {
                $tipo = $linha['t'] ?? null;
                if ($tipo === null || $tipo === '') {
                    continue;                       // destino em branco é escolha, não erro
                }
                if (!$existe($tipo, $linha['v'])) {
                    $p[] = [
                        'nivel' => 'erro',
                        'onde'  => sprintf($onde, (string) $linha['quem']),
                        'texto' => sprintf(
                            'aponta para %s %s, que não existe ou está inativo — a chamada vai '
                            . 'cair sem destino.',
                            $tipo,
                            (string) $linha['v']
                        ),
                    ];
                }
            }
        }

        // Fila sem agente nenhum não é erro de cadastro, mas é a razão
        // mais comum de "ninguém atende".
        foreach (self::consulta(
            'SELECT f.numero, f.nome, f.callcenter,
                    (SELECT COUNT(*) FROM fila_agentes a WHERE a.fila_id = f.id) AS n
               FROM filas f WHERE f.ativo = 1'
        ) as $f) {
            if ((int) $f['n'] === 0 && (int) $f['callcenter'] === 0) {
                $p[] = [
                    'nivel' => 'aviso',
                    'onde'  => "fila {$f['numero']}",
                    'texto' => 'está sem nenhum agente — ninguém vai atender.',
                ];
            }
        }

        // Grupo de toque guarda números, não chaves: um ramal excluído
        // deixa o grupo chamando um aparelho que não existe mais.
        foreach (self::consulta('SELECT numero, nome, ramais FROM grupos_toque WHERE ativo = 1') as $g) {
            foreach (array_filter(explode('-', (string) $g['ramais'])) as $r) {
                if (!in_array(trim($r), $ramais, true)) {
                    $p[] = [
                        'nivel' => 'erro',
                        'onde'  => "grupo de toque {$g['numero']}",
                        'texto' => "inclui o ramal {$r}, que não existe ou está inativo.",
                    ];
                }
            }
        }

        // Rota de saída sem tronco ativo não disca.
        foreach (self::consulta(
            'SELECT r.nome, t.ativo AS tronco_ativo, t.nome AS tronco
               FROM rotas_saida r JOIN troncos t ON t.id = r.tronco_id
              WHERE r.ativo = 1'
        ) as $r) {
            if ((int) $r['tronco_ativo'] === 0) {
                $p[] = [
                    'nivel' => 'erro',
                    'onde'  => "rota de saída {$r['nome']}",
                    'texto' => "usa o tronco {$r['tronco']}, que está desativado.",
                ];
            }
        }

        return $p;
    }

    /** @return list<array<string,mixed>> */
    private static function consulta(string $sql): array
    {
        try {
            return Bd::todos($sql);
        } catch (\Throwable) {
            // Tabela de um módulo que ainda não foi migrado não derruba a
            // conferência inteira.
            return [];
        }
    }

    /** @return string[] */
    private static function coluna(string $sql, string $campo): array
    {
        return array_map(
            static fn (array $l): string => (string) $l[$campo],
            self::consulta($sql)
        );
    }
}
