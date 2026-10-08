<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Todo código que se disca no telefone e cai em telium-recursos.
 *
 * Eles vêm de três cadastros — o catálogo de códigos de recurso, os
 * motivos de pausa do call center e as caixas postais avulsas, cada uma
 * com o seu código — e dividem um mesmo espaço. Um código não pode ser igual a outro nem ser
 * o começo de outro: o telefone que disca assim que reconhece um
 * código (*8) nunca chegaria ao mais comprido (*85), e o Asterisk
 * escolheria um dos dois.
 *
 * O código com argumento (*45 + fila, *27 + condição) ocupa tudo o que
 * começa com ele — a regra do começo já cobre isso.
 *
 * Os códigos apertados durante a chamada (featuremap) vivem em outro
 * espaço e ficam de fora.
 */
final class CodigosDiscagem
{
    /** O formato aceito para um código de pausa: * ou # e de 1 a 6 dígitos. */
    public const FORMATO = '/^[*#][0-9]{1,6}$/';

    /**
     * Os códigos ativos, menos o que está sendo gravado.
     *
     * @param string   $origem 'recurso', 'pausa' ou 'caixa' — de onde vem o que está sendo gravado
     * @param int|null $id     a linha que está sendo gravada (null na criação)
     * @param list<string> $chavesFora códigos de recurso regravados juntos, que entram com o valor novo
     * @return list<array{codigo:string, nome:string}>
     */
    public static function outros(string $origem, ?int $id, array $chavesFora = []): array
    {
        $recursos = array_filter(
            Bd::todos(
                "SELECT id, chave, codigo, nome FROM codigos_recurso
                  WHERE ativo = 1 AND tipo = 'dialplan' AND codigo <> ''"
            ),
            static fn (array $r): bool => !in_array((string) $r['chave'], $chavesFora, true)
        );
        $pausas = Bd::todos(
            "SELECT id, codigo, nome FROM cc_pausas_motivos
              WHERE ativo = 1 AND codigo IS NOT NULL AND codigo <> ''"
        );

        $caixas = Bd::todos(
            "SELECT id, codigo, nome FROM caixas_postais
              WHERE ativo = 1 AND codigo IS NOT NULL AND codigo <> ''"
        );

        $lista = [];
        foreach ($recursos as $r) {
            if ($origem !== 'recurso' || (int) $r['id'] !== $id) {
                $lista[] = ['codigo' => (string) $r['codigo'], 'nome' => (string) $r['nome']];
            }
        }
        foreach ($pausas as $p) {
            if ($origem !== 'pausa' || (int) $p['id'] !== $id) {
                $lista[] = ['codigo' => (string) $p['codigo'], 'nome' => 'Pausa: ' . $p['nome']];
            }
        }
        foreach ($caixas as $c) {
            if ($origem !== 'caixa' || (int) $c['id'] !== $id) {
                $lista[] = ['codigo' => (string) $c['codigo'], 'nome' => 'Caixa postal: ' . $c['nome']];
            }
        }

        return $lista;
    }

    /** Um é igual ao outro ou o começo dele? */
    public static function colidem(string $a, string $b): bool
    {
        return $a !== '' && $b !== '' && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /**
     * A mensagem de colisão do código, ou null se ele está livre.
     *
     * @param list<array{codigo:string, nome:string}>|null $outros já carregados, para conferir vários de uma vez
     */
    public static function colisao(string $codigo, string $origem, ?int $id, ?array $outros = null): ?string
    {
        foreach ($outros ?? self::outros($origem, $id) as $o) {
            if (self::colidem($codigo, $o['codigo'])) {
                return $codigo === $o['codigo']
                    ? "O código {$codigo} já é de \"{$o['nome']}\"."
                    : "Colide com \"{$o['nome']}\" ({$o['codigo']}): um é o começo do outro, e o telefone só alcançaria um deles.";
            }
        }

        return null;
    }
}
