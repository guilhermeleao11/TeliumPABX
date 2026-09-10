<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Hash de senha em PBKDF2-SHA256, formato:
 *   pbkdf2_sha256$<iteracoes>$<salt_b64>$<hash_b64>
 * Comparação em tempo constante.
 */
final class Senha
{
    private const ALGORITMO = 'sha256';
    private const ITERACOES = 390000;

    public static function criar(string $senha, int $iteracoes = self::ITERACOES): string
    {
        $salt = random_bytes(16);
        $hash = hash_pbkdf2(self::ALGORITMO, $senha, $salt, $iteracoes, 32, true);

        return sprintf('pbkdf2_sha256$%d$%s$%s', $iteracoes, base64_encode($salt), base64_encode($hash));
    }

    public static function verificar(string $senha, string $armazenado): bool
    {
        $partes = explode('$', $armazenado);
        if (count($partes) !== 4 || $partes[0] !== 'pbkdf2_sha256') {
            return false;
        }

        [, $iteracoes, $salt64, $hash64] = $partes;
        $salt = base64_decode($salt64, true);
        $esperado = base64_decode($hash64, true);
        if ($salt === false || $esperado === false) {
            return false;
        }

        $calculado = hash_pbkdf2(self::ALGORITMO, $senha, $salt, (int) $iteracoes, strlen($esperado), true);

        return hash_equals($esperado, $calculado);
    }

    /** Indica que o hash usa parâmetros antigos e vale regravar no próximo login. */
    public static function precisaAtualizar(string $armazenado): bool
    {
        $partes = explode('$', $armazenado);
        return count($partes) !== 4
            || $partes[0] !== 'pbkdf2_sha256'
            || (int) $partes[1] < self::ITERACOES;
    }
}
