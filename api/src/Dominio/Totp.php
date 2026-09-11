<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Código de seis dígitos que muda a cada trinta segundos (RFC 6238).
 *
 * O banco já guardava totp_secret e totp_ativo, e o login só perguntava
 * se o código tinha sido digitado — qualquer valor entrava. Quem ligasse
 * a verificação em dois passos ficava menos protegido do que quem não
 * ligasse. A conferência de verdade mora aqui.
 */
final class Totp
{
    private const DIGITOS = 6;
    private const PASSO   = 30;

    /** Quantos passos de 30s aceitar para trás e para frente. */
    private const JANELA = 1;

    /** Segredo novo em base32, do tamanho que os aplicativos esperam. */
    public static function gerarSegredo(): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $segredo = '';
        for ($i = 0; $i < 32; $i++) {
            $segredo .= $alfabeto[random_int(0, 31)];
        }

        return $segredo;
    }

    /**
     * O código confere?
     *
     * A janela de um passo para cada lado cobre o relógio do celular
     * fora de hora; mais que isso alarga demais o que um código vale.
     */
    public static function conferir(string $segredo, string $codigo, ?int $agora = null): bool
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) !== self::DIGITOS) {
            return false;
        }

        $chave = self::deBase32($segredo);
        if ($chave === null) {
            return false;
        }

        $passo = intdiv($agora ?? time(), self::PASSO);
        for ($d = -self::JANELA; $d <= self::JANELA; $d++) {
            // hash_equals: comparar em tempo constante evita que o tempo
            // de resposta conte quantos dígitos já estavam certos.
            if (hash_equals(self::codigoDoPasso($chave, $passo + $d), $codigo)) {
                return true;
            }
        }

        return false;
    }

    /** Qual passo de 30 s este código acertou, ou null. Serve contra repetição. */
    public static function passoUsado(string $segredo, string $codigo, ?int $agora = null): ?int
    {
        $chave = self::deBase32($segredo);
        if ($chave === null) {
            return null;
        }

        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        $passo = intdiv($agora ?? time(), self::PASSO);
        for ($d = -self::JANELA; $d <= self::JANELA; $d++) {
            if (hash_equals(self::codigoDoPasso($chave, $passo + $d), $codigo)) {
                return $passo + $d;
            }
        }

        return null;
    }

    /** URI que os aplicativos leem no QR Code. */
    public static function uri(string $segredo, string $conta, string $emissor): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d',
            rawurlencode($emissor),
            rawurlencode($conta),
            $segredo,
            rawurlencode($emissor),
            self::DIGITOS,
            self::PASSO
        );
    }

    private static function codigoDoPasso(string $chave, int $passo): string
    {
        $hash = hash_hmac('sha1', pack('J', $passo), $chave, true);
        $inicio = ord($hash[19]) & 0x0F;

        $numero = ((ord($hash[$inicio]) & 0x7F) << 24)
                | (ord($hash[$inicio + 1]) << 16)
                | (ord($hash[$inicio + 2]) << 8)
                | ord($hash[$inicio + 3]);

        return str_pad(
            (string) ($numero % (10 ** self::DIGITOS)),
            self::DIGITOS,
            '0',
            STR_PAD_LEFT
        );
    }

    /** Base32 (RFC 4648) para bytes, ou null se o segredo não for base32. */
    private static function deBase32(string $texto): ?string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $texto = strtoupper(rtrim(trim($texto), '='));
        if ($texto === '') {
            return null;
        }

        $bits = '';
        foreach (str_split($texto) as $c) {
            $i = strpos($alfabeto, $c);
            if ($i === false) {
                return null;
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $octeto) {
            if (strlen($octeto) === 8) {
                $bytes .= chr((int) bindec($octeto));
            }
        }

        return $bytes === '' ? null : $bytes;
    }
}
