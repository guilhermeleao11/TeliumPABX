<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

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

        return $resultado;
    }


    /**
     * Confere o destino antes de escrever.
     *
     * is_dir() devolve false tanto para "não existe" quanto para "não
     * consigo atravessar o diretório pai". Separar os dois casos evita
     * caçar um diretório que está lá o tempo todo.
     */
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
        @chmod($tmp, 0640);
        if (!rename($tmp, $caminho)) {
            @unlink($tmp);
            throw new \RuntimeException("Falha ao publicar {$caminho}");
        }
    }

    private function semData(string $conteudo): string
    {
        return preg_replace('/^; Gerado em .*$/m', '', $conteudo) ?? $conteudo;
    }
}
