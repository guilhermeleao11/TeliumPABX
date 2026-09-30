<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Ambiente;

/**
 * O nome que o Asterisk recebe para tocar um áudio enviado pelo console.
 *
 * O envio guarda só o nome base, e o arquivo fica em AUDIOS_DIR
 * (sounds/telium no servidor). O Asterisk procura um nome solto apenas
 * em sounds/<idioma>/ e em sounds/ — nunca em sounds/telium/ —, e um
 * Playback que não acha o arquivo só registra um aviso e segue: a URA,
 * a fila e a pesquisa ficavam mudas sem erro nenhum. Com o caminho
 * inteiro (sem extensão) ele acha o arquivo e ainda escolhe o formato.
 */
final class Som
{
    public static function prompt(?string $arquivo): string
    {
        $arquivo = (string) $arquivo;
        if ($arquivo === '') {
            return '';
        }

        return rtrim((string) Ambiente::get('AUDIOS_DIR', '/var/lib/asterisk/sounds/telium'), '/')
             . '/' . $arquivo;
    }
}
