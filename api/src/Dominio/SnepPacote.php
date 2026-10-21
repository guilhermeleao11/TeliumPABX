<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * O pacote que o comando da tela gera no servidor do SNEP
 * (snep-telium.tar.gz):
 *
 *   snep.sql   o banco, só com as tabelas da migração (sem CDR)
 *   sons/      os áudios que as regras, URAs e filas usam
 *   moh/       as músicas de espera, uma pasta por classe
 *   conf/      os .conf do Asterisk do SNEP (os contextos próprios)
 *   agentes-filas.txt   quantas vezes cada agente entrou em cada fila
 *              nos últimos 90 dias (do queue_log), "  N fila|agente"
 *
 * Nada do pacote é gravado com o caminho que veio nele: cada arquivo vai
 * para a pasta de trabalho com um nome limpo, e só os tipos esperados —
 * link, pasta e dispositivo do tar são pulados.
 * Os limites de quantidade e de tamanho descompactado seguram o pacote
 * malformado (ou malicioso) antes de ele encher o disco.
 */
final class SnepPacote
{
    private const AUDIO = ['wav', 'gsm', 'sln', 'ulaw', 'alaw', 'mp3', 'ogg', 'g722'];
    private const MAX_ARQUIVOS = 5000;
    private const MAX_BYTES = 400 * 1024 * 1024;

    /** É um tar.gz (e não um .sql.gz)? */
    public static function ehPacote(string $nomeOriginal): bool
    {
        return (bool) preg_match('/\.(tar\.gz|tgz)$/i', $nomeOriginal);
    }

    /**
     * Extrai para $destino e devolve o que achou.
     *
     * @return array{sql:?string, sons:array<string,string>, moh:array<string,list<string>>, conf:list<string>, historico:?string}
     */
    public static function extrair(string $arquivo, string $destino): array
    {
        $achado = ['sql' => null, 'sons' => [], 'moh' => [], 'conf' => [], 'historico' => null];
        $quantos = 0;
        $bytes = 0;

        foreach (self::entradas($arquivo) as [$caminho, $tamanho, $ler]) {
            if (++$quantos > self::MAX_ARQUIVOS) {
                throw new \RuntimeException('O pacote tem arquivos demais. Gere-o de novo pelo comando da tela.');
            }
            $bytes += $tamanho;
            if ($bytes > self::MAX_BYTES) {
                throw new \RuntimeException('O pacote descompactado passa de 400 MB. Gere-o de novo pelo comando da tela.');
            }

            $partes = array_values(array_filter(explode('/', $caminho), static fn ($p) => $p !== '' && $p !== '.' && $p !== '..'));
            if ($partes === []) {
                continue;
            }
            $nome = self::limpo(end($partes));
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

            $para = null;
            if ($nome === 'agentes-filas.txt' && count($partes) === 1) {
                $para = $achado['historico'] = "{$destino}/agentes-filas.txt";
            } elseif ($ext === 'sql' && $achado['sql'] === null) {
                $para = $achado['sql'] = "{$destino}/snep.sql";
            } elseif ($partes[0] === 'sons' && in_array($ext, self::AUDIO, true)
                      && !isset($achado['sons'][pathinfo($nome, PATHINFO_FILENAME)])) {
                $para = $achado['sons'][pathinfo($nome, PATHINFO_FILENAME)] = "{$destino}/sons/{$nome}";
            } elseif ($partes[0] === 'moh' && in_array($ext, self::AUDIO, true)) {
                $classe = count($partes) > 2 ? self::limpo($partes[count($partes) - 2]) : '_raiz';
                $para = $achado['moh'][$classe][] = "{$destino}/moh/{$classe}/{$nome}";
            } elseif ($partes[0] === 'conf' && $ext === 'conf') {
                $para = $achado['conf'][] = "{$destino}/conf/" . self::limpo(implode('__', array_slice($partes, 1)));
            }
            if ($para !== null) {
                self::gravar($para, $ler);
            }
        }

        if ($achado['sql'] === null) {
            throw new \RuntimeException('O pacote não tem o dump do banco (snep.sql). Gere-o de novo pelo comando da tela.');
        }
        foreach ($achado['moh'] as &$lista) {
            sort($lista);
        }

        return $achado;
    }

    /**
     * As entradas de arquivo comum de um .tar.gz: caminho, tamanho e quem
     * lê o conteúdo. Lido aqui, cabeçalho por cabeçalho (512 bytes), e não
     * pelo PharData, que não lista um tar gerado com "tar czf x.tgz ." (os
     * nomes começam com "./"). Link, pasta e dispositivo são pulados.
     *
     * @return \Generator<array{0:string, 1:int, 2:callable(): \Generator<string>}>
     */
    private static function entradas(string $arquivo): \Generator
    {
        $f = @fopen('compress.zlib://' . $arquivo, 'rb');
        if ($f === false) {
            throw new \RuntimeException('O arquivo não é um pacote .tar.gz válido.');
        }
        try {
            $nomeLongo = null;
            while (true) {
                $cabecalho = self::ler($f, 512);
                if (strlen($cabecalho) < 512 || trim($cabecalho, "\0") === '') {
                    break;      // fim do tar: bloco zerado
                }
                $soma = octdec(trim(substr($cabecalho, 148, 8), " \0"));
                $conta = 0;
                for ($i = 0; $i < 512; $i++) {
                    $conta += ($i >= 148 && $i < 156) ? 32 : ord($cabecalho[$i]);
                }
                if ($soma !== $conta) {
                    throw new \RuntimeException('O arquivo não é um pacote .tar.gz válido.');
                }

                $tipo = $cabecalho[156];
                $tamanho = (int) octdec(trim(substr($cabecalho, 124, 12), " \0"));
                $nome = rtrim(substr($cabecalho, 0, 100), "\0");
                if (substr($cabecalho, 257, 5) === 'ustar') {
                    $prefixo = rtrim(substr($cabecalho, 345, 155), "\0");
                    $nome = $prefixo !== '' ? "{$prefixo}/{$nome}" : $nome;
                }
                $blocos = (int) ceil($tamanho / 512) * 512;

                if ($tipo === 'L') {            // nome longo do GNU tar: vale para a próxima entrada
                    $nomeLongo = rtrim(self::ler($f, $blocos), "\0");
                    continue;
                }
                if ($nomeLongo !== null) {
                    $nome = $nomeLongo;
                    $nomeLongo = null;
                }
                if ($tipo !== '0' && $tipo !== "\0") {
                    self::ler($f, $blocos);      // pasta, link, cabeçalho pax: pula
                    continue;
                }

                $lido = false;
                $conteudo = static function () use ($f, $tamanho, $blocos, &$lido): \Generator {
                    $lido = true;
                    $falta = $tamanho;
                    while ($falta > 0) {
                        $pedaco = self::ler($f, min(65536, $falta));
                        if ($pedaco === '') {
                            break;
                        }
                        $falta -= strlen($pedaco);
                        yield $pedaco;
                    }
                    self::ler($f, $blocos - $tamanho);
                };
                yield [$nome, $tamanho, $conteudo];
                if (!$lido) {
                    self::ler($f, $blocos);
                }
            }
        } finally {
            fclose($f);
        }
    }

    /** Lê exatamente $n bytes (o fluxo descompactado entrega aos pedaços). */
    private static function ler($f, int $n): string
    {
        $dados = '';
        while ($n > 0 && !feof($f)) {
            $pedaco = fread($f, $n);
            if ($pedaco === false || $pedaco === '') {
                break;
            }
            $dados .= $pedaco;
            $n -= strlen($pedaco);
        }

        return $dados;
    }

    /**
     * As classes de música de espera dos .conf do SNEP: classe => pasta.
     *
     * @param list<string> $confs
     * @return array<string,string>
     */
    public static function classesDeMusica(array $confs): array
    {
        $classes = [];
        foreach ($confs as $arquivo) {
            if (!str_contains(basename($arquivo), 'musiconhold')) {
                continue;
            }
            $atual = null;
            foreach (file($arquivo) ?: [] as $linha) {
                if (preg_match('/^\s*\[([^\]]+)\]/', $linha, $m) === 1) {
                    $atual = trim($m[1]);
                } elseif ($atual !== null && preg_match('/^\s*directory\s*=>?\s*(\S+)/i', $linha, $m) === 1) {
                    $classes[$atual] = basename(rtrim($m[1], '/'));
                }
            }
        }

        return $classes;
    }

    /**
     * O texto de um contexto do dialplan, se algum .conf do SNEP o tem.
     *
     * @param list<string> $confs
     */
    public static function contexto(array $confs, string $nome): ?string
    {
        foreach ($confs as $arquivo) {
            $linhas = file($arquivo) ?: [];
            foreach ($linhas as $i => $linha) {
                if (preg_match('/^\[' . preg_quote($nome, '/') . '\]/', $linha) !== 1) {
                    continue;
                }
                $bloco = [rtrim($linha)];
                for ($j = $i + 1; $j < count($linhas) && preg_match('/^\[/', $linhas[$j]) !== 1; $j++) {
                    $bloco[] = rtrim($linhas[$j]);
                }

                return rtrim(implode("\n", $bloco)) . "\n";
            }
        }

        return null;
    }

    /**
     * As filas em que cada agente entrou, do histórico do pacote:
     * fila => [agente => quantas vezes].
     *
     * @return array<string, array<string,int>>
     */
    public static function historico(?string $arquivo): array
    {
        $filas = [];
        foreach ($arquivo !== null && is_file($arquivo) ? (file($arquivo) ?: []) : [] as $linha) {
            if (preg_match('/^\s*(\d+)\s+([^|]+)\|(.+)$/', rtrim($linha), $m) === 1) {
                $filas[trim($m[2])][trim($m[3])] = ($filas[trim($m[2])][trim($m[3])] ?? 0) + (int) $m[1];
            }
        }

        return $filas;
    }

    private static function limpo(string $nome): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $nome), '._') ?: 'arquivo';
    }

    /** @param callable(): \Generator<string> $ler */
    private static function gravar(string $para, callable $ler): void
    {
        if (!is_dir(dirname($para))) {
            @mkdir(dirname($para), 0700, true);
        }
        $saida = @fopen($para, 'wb');
        if ($saida === false) {
            throw new \RuntimeException('Não foi possível extrair ' . basename($para) . ' do pacote.');
        }
        foreach ($ler() as $pedaco) {
            fwrite($saida, $pedaco);
        }
        fclose($saida);
        @chmod($para, 0600);
    }
}
