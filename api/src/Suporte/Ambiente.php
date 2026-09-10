<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * Leitor mínimo de .env — sem dependência externa de propósito:
 * menos peça para dar errado no provisionamento da VM.
 */
final class Ambiente
{
    private static array $valores = [];

    public static function carregar(string $arquivo): void
    {
        if (!is_readable($arquivo)) {
            throw new \RuntimeException("Arquivo de ambiente não encontrado: {$arquivo}");
        }

        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) {
                continue;
            }
            [$chave, $valor] = array_pad(explode('=', $linha, 2), 2, '');
            $valor = trim($valor, " \t\"'");
            self::$valores[trim($chave)] = $valor;
        }
    }

    public static function get(string $chave, ?string $padrao = null): ?string
    {
        return self::$valores[$chave] ?? $padrao;
    }

    public static function bool(string $chave, bool $padrao = false): bool
    {
        $v = strtolower((string) self::get($chave, $padrao ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $chave, int $padrao = 0): int
    {
        return (int) (self::get($chave, (string) $padrao) ?? $padrao);
    }
}
