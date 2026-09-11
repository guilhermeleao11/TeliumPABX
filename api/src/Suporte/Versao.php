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
 */
final class Versao
{
    public const NUMERO = '1.0.1';
    public const NOME = 'Telium PABX';

    /** "Telium PABX 1.0.1" */
    public static function completa(): string
    {
        return self::NOME . ' ' . self::NUMERO;
    }
}
