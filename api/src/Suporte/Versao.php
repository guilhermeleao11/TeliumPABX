<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * A versão do sistema, num lugar só.
 *
 * Estava escrita à mão em três arquivos e as três discordavam: a API
 * dizia 1.0.0 e o console dizia 3.4.1. Num produto que vai ser revendido
 * e atualizado, saber qual versão está rodando é o primeiro passo de
 * qualquer suporte.
 *
 * O número sobe a cada entrega (correção: 1.1.1; novidade: 1.2.0) e o
 * que mudou vai para o api/CHANGELOG.md, que a tela Telium › Versões e
 * Novidades mostra. Ao lado do número vai o commit publicado: até a 1.1.0
 * o número ficou parado em 1.0.1 por várias entregas, e só o commit dizia
 * qual código estava rodando.
 */
final class Versao
{
    public const NUMERO = '1.2.1';
    public const NOME = 'Telium PABX';

    /** "Telium PABX 1.1.0" */
    public static function completa(): string
    {
        return self::NOME . ' ' . self::NUMERO;
    }

    /** "1.1.0 (ae4f854)", ou só o número quando o commit não é conhecido. */
    public static function exibicao(): string
    {
        $c = self::commit();

        return self::NUMERO . ($c !== '' ? " ({$c})" : '');
    }

    /**
     * O commit publicado. Na central, o playbook grava api/COMMIT; num
     * clone de desenvolvimento, vale o HEAD do próprio Git.
     */
    public static function commit(): string
    {
        static $commit = null;
        if ($commit !== null) {
            return $commit;
        }

        $api = dirname(__DIR__, 2);
        $arquivo = $api . '/COMMIT';
        if (is_file($arquivo)) {
            return $commit = substr(trim((string) file_get_contents($arquivo)), 0, 16);
        }

        $git = dirname($api) . '/.git';
        $head = is_file("{$git}/HEAD") ? trim((string) file_get_contents("{$git}/HEAD")) : '';
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $head = is_file("{$git}/{$ref}") ? trim((string) file_get_contents("{$git}/{$ref}")) : '';
            if ($head === '' && is_file("{$git}/packed-refs")) {
                foreach (file("{$git}/packed-refs") ?: [] as $linha) {
                    if (str_ends_with(trim($linha), " {$ref}")) {
                        $head = strtok($linha, ' ') ?: '';
                        break;
                    }
                }
            }
        }

        return $commit = preg_match('/^[0-9a-f]{7,40}$/', $head) === 1 ? substr($head, 0, 7) : '';
    }
}
