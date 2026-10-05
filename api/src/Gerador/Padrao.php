<?php
declare(strict_types=1);

namespace Telium\Gerador;

/**
 * Padrão de discagem do jeito que a pessoa escreve.
 *
 * O Asterisk só trata como padrão o que começa com "_", e só entende X, Z
 * e N maiúsculos: "x." ou "0xx xxxx xxxx" virava um número literal que
 * nunca casava, e a rota ficava muda. Aqui o padrão ganha o "_", as letras
 * viram maiúsculas e os espaços somem. Número exato fica como está.
 *
 *   x.            → _X.        (qualquer número, de dois dígitos ou mais)
 *   0xx xxxx-xxxx → _0XXXXXXXXXX   (o "-" de fora dos colchetes é só visual)
 *   [2-5]xxxxxxx  → _[2-5]XXXXXXX
 *   1140041000    → 1140041000
 */
final class Padrao
{
    public static function normalizar(?string $valor): string
    {
        $v = preg_replace('/\s+/', '', (string) $valor) ?? '';
        // Só mexe no que parece padrão ou número; "*", "s" e "qualquer"
        // (os coringas da rota de entrada) passam como estão.
        if ($v === '' || preg_match('/^_?[0-9XZNxzn.!\[\]*#+-]+$/', $v) !== 1 || $v === '*') {
            return $v;
        }

        $v = ltrim($v, '_');
        // O hífen separa grupos de dígitos ("3325-5800"); dentro de
        // colchetes ele é intervalo ("[2-5]") e fica.
        $v = preg_replace_callback('/\[[^\]]*\]|-/', static fn (array $m): string => $m[0] === '-' ? '' : $m[0], $v) ?? $v;
        $v = strtr($v, ['x' => 'X', 'z' => 'Z', 'n' => 'N']);

        return preg_match('/[XZN.!\[]/', $v) === 1 ? '_' . $v : $v;
    }

    /** "Qualquer número": X., X!, . ou ! — com ou sem "_". */
    public static function ehTudo(?string $valor): bool
    {
        return in_array(self::normalizar($valor), ['_X.', '_X!', '_.', '_!'], true);
    }
}
