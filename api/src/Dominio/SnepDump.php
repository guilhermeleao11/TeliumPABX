<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Lê o dump do banco do SNEP 3 (mysqldump/mariadb-dump do banco "snep").
 *
 * Só as tabelas que a migração usa saem daqui; o resto do arquivo (o CDR,
 * que é quase todo o tamanho dele) é pulado linha a linha, sem ir para a
 * memória. Aceita o .sql puro, o .sql.gz e o .sql.bz2, e o dump com uma
 * linha de dados por registro (mariadb-dump) ou todas numa linha só
 * (mysqldump --extended-insert).
 *
 * A ordem das colunas vem do CREATE TABLE do próprio dump — o SNEP mudou
 * de coluna entre versões, e adivinhar a ordem era trocar senha por nome.
 */
final class SnepDump
{
    /** As tabelas que a migração lê. */
    public const TABELAS = [
        'peers', 'trunks', 'queues', 'queue_members',
        'regras_negocio', 'regras_negocio_actions', 'regras_negocio_actions_config',
        'expr_alias', 'expr_alias_expression', 'sounds',
        // Agentes (login na fila por código) e as filas de cada um.
        'agtfila', 'queues_agent',
    ];

    /**
     * @return array<string, list<array<string, ?string>>> registros por tabela
     * @throws \RuntimeException quando o arquivo não é um dump do SNEP
     */
    public static function ler(string $arquivo): array
    {
        $fluxo = self::abrir($arquivo);

        $colunas = [];
        $dados = array_fill_keys(self::TABELAS, []);
        $criando = null;          // tabela cujo CREATE TABLE está sendo lido
        $inserindo = null;        // tabela do INSERT em andamento (null = pular)
        $emInsert = false;
        $acumulado = '';

        try {
            while (($linha = fgets($fluxo)) !== false) {
                $linha = rtrim($linha, "\r\n");

                if ($criando !== null) {
                    if (preg_match('/^\s*`([^`]+)`\s/', $linha, $m) === 1) {
                        $colunas[$criando][] = $m[1];
                    } elseif (str_starts_with(ltrim($linha), ')')) {
                        $criando = null;
                    }
                    continue;
                }

                if (!$emInsert) {
                    if (preg_match('/^CREATE TABLE `([^`]+)`/', $linha, $m) === 1) {
                        $criando = in_array($m[1], self::TABELAS, true) ? $m[1] : null;
                        if ($criando !== null) {
                            $colunas[$criando] = [];
                        }
                        continue;
                    }
                    if (preg_match('/^INSERT INTO `([^`]+)`/', $linha, $m) !== 1) {
                        continue;
                    }
                    $emInsert = true;
                    $inserindo = in_array($m[1], self::TABELAS, true) ? $m[1] : null;
                    $acumulado = '';
                }

                // Uma linha física nunca parte uma string (o dump escreve a
                // quebra como \n), então o comando acaba na linha que
                // termina em ";".
                if ($inserindo !== null) {
                    $acumulado .= $linha . "\n";
                }
                if (str_ends_with($linha, ';')) {
                    if ($inserindo !== null) {
                        foreach (self::tuplas($acumulado) as $valores) {
                            $dados[$inserindo][] = self::comNomes($inserindo, $colunas[$inserindo] ?? [], $valores);
                        }
                    }
                    $emInsert = false;
                    $inserindo = null;
                    $acumulado = '';
                }
            }
        } finally {
            fclose($fluxo);
        }

        if (!isset($colunas['peers'], $colunas['regras_negocio'])) {
            throw new \RuntimeException(
                'Este arquivo não parece o dump do banco do SNEP: faltam as tabelas peers e regras_negocio. '
                . 'Gere o dump do banco "snep" com a estrutura (sem --no-create-info).'
            );
        }

        return $dados;
    }

    /**
     * Os valores de cada registro de um INSERT ... VALUES (...),(...);
     *
     * @return list<list<?string>>
     */
    public static function tuplas(string $sql): array
    {
        $inicio = stripos($sql, 'VALUES');
        if ($inicio === false) {
            return [];
        }

        $tuplas = [];
        $atual = null;            // registro em montagem
        $valor = '';
        $temValor = false;        // houve algo desde a última vírgula
        $aspas = false;
        $texto = false;           // o valor atual veio entre aspas
        $n = strlen($sql);

        for ($i = $inicio + 6; $i < $n; $i++) {
            $c = $sql[$i];

            if ($aspas) {
                if ($c === '\\' && $i + 1 < $n) {
                    $i++;
                    $valor .= match ($sql[$i]) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a",
                        default => $sql[$i],
                    };
                } elseif ($c === "'" && ($sql[$i + 1] ?? '') === "'") {
                    $valor .= "'";
                    $i++;
                } elseif ($c === "'") {
                    $aspas = false;
                } else {
                    $valor .= $c;
                }
                continue;
            }

            if ($atual === null) {
                if ($c === '(') {
                    $atual = [];
                    $valor = '';
                    $temValor = false;
                    $texto = false;
                } elseif ($c === ';') {
                    break;
                }
                continue;
            }

            if ($c === "'") {
                $aspas = true;
                $texto = true;
                $temValor = true;
            } elseif ($c === ',' || $c === ')') {
                $atual[] = (!$texto && strtoupper(trim($valor)) === 'NULL') ? null : ($texto ? $valor : trim($valor));
                $valor = '';
                $texto = false;
                $temValor = false;
                if ($c === ')') {
                    $tuplas[] = $atual;
                    $atual = null;
                }
            } elseif ($texto) {
                // Depois da aspa que fecha, só vem a vírgula ou o parêntese.
                continue;
            } elseif (!ctype_space($c) || $temValor) {
                $valor .= $c;
                $temValor = true;
            }
        }

        return $tuplas;
    }

    /** @return array<string, ?string> */
    private static function comNomes(string $tabela, array $colunas, array $valores): array
    {
        if (count($colunas) !== count($valores)) {
            throw new \RuntimeException(
                "A tabela {$tabela} do dump tem " . count($valores) . ' valores por registro e '
                . count($colunas) . ' colunas na estrutura. O dump está cortado ou foi gerado sem a estrutura.'
            );
        }

        return array_combine($colunas, $valores);
    }

    /** @return resource */
    private static function abrir(string $arquivo)
    {
        $cabeca = (string) @file_get_contents($arquivo, false, null, 0, 3);
        if (str_starts_with($cabeca, 'BZh')) {
            if (!function_exists('bzopen')) {
                throw new \RuntimeException(
                    'O PHP desta central não abre .bz2 (falta a extensão bz2). Rode a atualização, '
                    . 'ou descompacte o dump antes de enviar.'
                );
            }
            $caminho = 'compress.bzip2://' . $arquivo;
        } elseif (str_starts_with($cabeca, "\x1f\x8b")) {
            $caminho = 'compress.zlib://' . $arquivo;
        } else {
            $caminho = $arquivo;
        }

        $fluxo = @fopen($caminho, 'rb');
        if ($fluxo === false) {
            throw new \RuntimeException('Não foi possível abrir o arquivo do dump.');
        }

        return $fluxo;
    }
}
