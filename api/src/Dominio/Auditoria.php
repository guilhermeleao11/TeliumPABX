<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

final class Auditoria
{
    public static function registrar(
        ?array $usuario,
        string $acao,
        string $modulo,
        ?string $objeto = null,
        array $detalhe = [],
        ?string $ip = null,
    ): void {
        Bd::executar(
            'INSERT INTO auditoria (usuario_id, usuario_nome, acao, modulo, objeto, detalhe, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $usuario['id']   ?? null,
                $usuario['nome'] ?? null,
                $acao,
                $modulo,
                $objeto,
                $detalhe === [] ? null : json_encode($detalhe, JSON_UNESCAPED_UNICODE),
                $ip,
            ]
        );
    }
}
