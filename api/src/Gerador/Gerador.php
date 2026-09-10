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

    /** @return array<string,array{status:string,bytes:int}> */
    public function gerar(): array
    {
        if (!is_dir($this->destino)) {
            throw new \RuntimeException("Diretório de destino não existe: {$this->destino}");
        }
        if (!is_writable($this->destino)) {
            throw new \RuntimeException(
                "Sem permissão de escrita em {$this->destino} — o usuário " .
                (posix_getpwuid(posix_geteuid())['name'] ?? '?') .
                ' precisa pertencer ao grupo asterisk.'
            );
        }

        $arquivos = [
            ...(new GeradorPjsip())->gerar(),
            ...(new GeradorDialplan())->gerar(),
            ...(new GeradorFilas())->gerar(),
            ...(new GeradorVoicemail())->gerar(),
        ];

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
