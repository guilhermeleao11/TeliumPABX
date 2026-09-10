<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Permissões vivem no banco. O front recebe a lista já resolvida,
 * mas TODA verificação real acontece aqui, no servidor.
 */
final class Permissoes
{
    /** @return array{allow:string[], caps:string[]} */
    public static function doPerfil(int $perfilId): array
    {
        $allow = array_column(
            Bd::todos('SELECT modulo FROM perfil_modulos WHERE perfil_id = ?', [$perfilId]),
            'modulo'
        );
        $caps = array_column(
            Bd::todos('SELECT acao FROM perfil_acoes WHERE perfil_id = ?', [$perfilId]),
            'acao'
        );

        return ['allow' => $allow, 'caps' => $caps];
    }

    /** O perfil enxerga o módulo? Aceita '*' e 'grupo.*'. */
    public static function podeModulo(array $allow, string $modulo): bool
    {
        foreach ($allow as $regra) {
            if ($regra === '*' || $regra === $modulo) {
                return true;
            }
            if (str_ends_with($regra, '.*') && str_starts_with($modulo, substr($regra, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    public static function podeAcao(array $caps, string $acao): bool
    {
        return in_array($acao, $caps, true);
    }
}
