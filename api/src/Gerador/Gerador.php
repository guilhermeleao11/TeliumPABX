<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Trava;

/**
 * Orquestra a geração dos .conf a partir do banco.
 *
 * Escreve de forma atômica (arquivo temporário + rename) para o Asterisk
 * nunca enxergar um arquivo pela metade, e só marca "configuração pendente"
 * quando algo realmente mudou.
 */
final class Gerador
{
    private readonly string $destino;

    public function __construct(?string $destino = null)
    {
        $this->destino = rtrim($destino ?? (string) Ambiente::get('ASTERISK_GERADO_DIR', '/etc/asterisk/telium'), '/');
    }

    /**
     * Monta o conteúdo de todos os arquivos, sem tocar no disco.
     *
     * @return array<string,string>
     */
    private function arquivos(): array
    {
        return [
            ...(new GeradorPjsip())->gerar(),
            ...(new GeradorDialplan())->gerar(),
            ...(new GeradorRecursos())->gerar(),
            ...(new GeradorFilas())->gerar(),
            ...(new GeradorConferencias())->gerar(),
            ...(new GeradorEstacionamento())->gerar(),
            ...(new GeradorVoicemail())->gerar(),
        ];
    }

    /**
     * O que mudaria se gerássemos agora, sem escrever nada.
     *
     * @return array<string,string> arquivo => inalterado|criado|atualizado
     */
    public function simular(): array
    {
        $resultado = [];

        foreach ($this->arquivos() as $nome => $conteudo) {
            $caminho = "{$this->destino}/{$nome}";
            $anterior = is_file($caminho) ? (string) file_get_contents($caminho) : null;

            $resultado[$nome] = $anterior === null
                ? 'criado'
                : ($this->semData($anterior) === $this->semData($conteudo) ? 'inalterado' : 'atualizado');
        }

        return $resultado;
    }

    /** @return array<string,array{status:string,bytes:int}> */
    public function gerar(): array
    {
        $this->conferirDestino();

        // Cada arquivo já é escrito de forma atômica, mas dois geradores
        // ao mesmo tempo podem deixar metade dos arquivos de uma versão
        // e metade de outra. A trava é a mesma da aplicação, porque
        // aplicar é gerar e recarregar.
        $trava = Trava::tentar('telium_aplicar', 20);
        if ($trava === null) {
            throw new \RuntimeException(
                'Outra geração de configuração está em andamento. Tente de novo em instantes.'
            );
        }

        $arquivos = $this->arquivos();

        $resultado = [];
        $mudou = false;

        foreach ($arquivos as $nome => $conteudo) {
            $caminho = "{$this->destino}/{$nome}";
            $anterior = is_file($caminho) ? (string) file_get_contents($caminho) : null;

            // Comparação ignorando a linha de data, para não marcar mudança à toa
            if ($anterior !== null && $this->semData($anterior) === $this->semData($conteudo)) {
                $resultado[$nome] = ['status' => 'inalterado', 'bytes' => strlen($conteudo)];
                continue;
            }

            $this->escreverAtomico($caminho, $conteudo);
            $resultado[$nome] = [
                'status' => $anterior === null ? 'criado' : 'atualizado',
                'bytes'  => strlen($conteudo),
            ];
            $mudou = true;
        }

        if ($mudou) {
            Bd::executar(
                "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
                 ON DUPLICATE KEY UPDATE valor = '1'"
            );
        }

        // Uma conferência só, no primeiro arquivo: dono e modo são
        // iguais para todos, e o erro que ela pega é mudo demais para
        // ficar sem aviso.
        $primeiro = array_key_first($resultado);
        if ($primeiro !== null) {
            $problema = $this->conferirLeitura("{$this->destino}/{$primeiro}");
            if ($problema !== null) {
                throw new \RuntimeException($problema);
            }
        }

        return $resultado;
    }


    /**
     * Confere o destino antes de escrever.
     *
     * is_dir() devolve false tanto para "não existe" quanto para "não
     * consigo atravessar o diretório pai". Separar os dois casos evita
     * caçar um diretório que está lá o tempo todo.
     */
    /** Grupo que o Asterisk usa para ler o que a API gera. */
    private const GRUPO_ASTERISK = 'asterisk';

    private function conferirDestino(): void
    {
        $pai = dirname($this->destino);
        $quem = $this->identidade();

        if (!is_dir($pai)) {
            throw new \RuntimeException(
                "O diretório {$pai} não existe. O Asterisk chegou a ser instalado? ({$quem})"
            );
        }

        if (!is_executable($pai)) {
            throw new \RuntimeException(
                "Sem permissão para entrar em {$pai}. Provavelmente ele está 0750 " .
                "asterisk:asterisk e o usuário da API não pertence ao grupo asterisk. ({$quem})"
            );
        }

        if (!is_dir($this->destino)) {
            throw new \RuntimeException(
                "O diretório {$this->destino} não existe — crie-o com dono " .
                $this->usuario() . ", grupo asterisk e modo 2775. ({$quem})"
            );
        }

        if (!is_writable($this->destino)) {
            throw new \RuntimeException(
                "Sem permissão de escrita em {$this->destino}. " .
                "Confira o modo do diretório (esperado 2775, grupo asterisk). ({$quem})"
            );
        }
    }

    /** Nome do usuário efetivo. */
    private function usuario(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'o usuário da API';
        }

        return posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid();
    }

    /** Descreve quem está executando, para a mensagem de erro ser acionável. */
    private function identidade(): string
    {
        if (!function_exists('posix_geteuid')) {
            return 'usuário desconhecido — extensão posix ausente';
        }

        $usuario = $this->usuario();
        $grupos = array_map(
            static fn (int $gid): string => posix_getgrgid($gid)['name'] ?? (string) $gid,
            posix_getgroups()
        );

        return "rodando como {$usuario}, grupos: " . implode(',', $grupos);
    }

    public function pendente(): bool
    {
        return Bd::valor("SELECT valor FROM sistema WHERE chave = 'config_pendente'") === '1';
    }

    private function escreverAtomico(string $caminho, string $conteudo): void
    {
        $tmp = $caminho . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $conteudo, LOCK_EX) === false) {
            throw new \RuntimeException("Falha ao escrever {$tmp}");
        }

        // 0640 com grupo asterisk: a API escreve, o Asterisk lê, e mais
        // ninguém. O grupo normalmente vem do setgid do diretório; o
        // chgrp aqui é o cinto de segurança para quando alguém recria o
        // diretório à mão e perde o bit — sem ele, todo arquivo gerado
        // fica ilegível para o Asterisk e a central segue com a
        // configuração antiga sem dizer nada.
        @chmod($tmp, 0640);
        @chgrp($tmp, self::GRUPO_ASTERISK);

        if (!rename($tmp, $caminho)) {
            @unlink($tmp);
            throw new \RuntimeException("Falha ao publicar {$caminho}");
        }
    }

    /**
     * O Asterisk consegue ler o que acabamos de escrever?
     *
     * Roda uma vez por geração, sobre um arquivo só. O modo e o grupo
     * são o que decide, e errar isso é silencioso: o gerador diz
     * "atualizado", o Asterisk recarrega, e nada muda.
     *
     * @return string|null o problema, ou null quando está tudo certo
     */
    private function conferirLeitura(string $caminho): ?string
    {
        if (!is_file($caminho)) {
            return null;
        }

        $dono = posix_getpwuid((int) fileowner($caminho))['name'] ?? '?';
        $grupo = posix_getgrgid((int) filegroup($caminho))['name'] ?? '?';
        $modo = substr(sprintf('%o', fileperms($caminho)), -4);

        // Legível pelo grupo, e o grupo é o do Asterisk?
        $grupoLe = (fileperms($caminho) & 0040) !== 0;
        $outrosLeem = (fileperms($caminho) & 0004) !== 0;

        if ($outrosLeem || ($grupoLe && $grupo === self::GRUPO_ASTERISK)) {
            return null;
        }

        return sprintf(
            'O Asterisk não vai conseguir ler %s (%s %s:%s). '
            . 'O diretório %s precisa estar com o bit setgid e grupo %s — '
            . 'sem isso a central recarrega e continua com a configuração anterior.',
            basename($caminho),
            $modo,
            $dono,
            $grupo,
            $this->destino,
            self::GRUPO_ASTERISK
        );
    }

    private function semData(string $conteudo): string
    {
        return preg_replace('/^; Gerado em .*$/m', '', $conteudo) ?? $conteudo;
    }
}
