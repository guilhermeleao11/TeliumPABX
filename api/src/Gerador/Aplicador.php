<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Ami;
use Telium\Suporte\Bd;

/**
 * "Aplicar configurações": recarrega os módulos do Asterisk pelo AMI.
 * Nada de sudo — a API só fala com o AMI em 127.0.0.1.
 */
final class Aplicador
{
    private const RECARGAS = [
        'pjsip reload'            => 'endpoints e troncos',
        'dialplan reload'         => 'dialplan',
        'module reload app_queue' => 'filas',
        'voicemail reload'        => 'correio de voz',
    ];

    /** @return array{sucesso:bool, etapas:array<string,string>, saida:string} */
    public function aplicar(?int $usuarioId = null, array $arquivos = []): array
    {
        $etapas = [];
        $saida = '';
        $sucesso = true;

        try {
            $ami = Ami::compartilhada();

            foreach (self::RECARGAS as $comando => $descricao) {
                $resposta = $ami->comando($comando);
                $ok = !str_contains(strtolower($resposta), 'no such command')
                    && !str_contains(strtolower($resposta), 'error');
                $etapas[$descricao] = $ok ? 'ok' : 'falhou';
                $saida .= "\$ {$comando}\n" . trim($resposta) . "\n\n";
                $sucesso = $sucesso && $ok;
            }
        } catch (\Throwable $e) {
            $sucesso = false;
            $saida .= 'ERRO: ' . $e->getMessage() . "\n";
        }

        Bd::executar(
            'INSERT INTO config_aplicacoes (usuario_id, arquivos, reloads, sucesso, saida)
             VALUES (?, ?, ?, ?, ?)',
            [
                $usuarioId,
                json_encode($arquivos, JSON_UNESCAPED_UNICODE),
                json_encode($etapas, JSON_UNESCAPED_UNICODE),
                $sucesso ? 1 : 0,
                mb_substr($saida, 0, 60000),
            ]
        );

        if ($sucesso) {
            Bd::executar(
                "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','0')
                 ON DUPLICATE KEY UPDATE valor = '0'"
            );
        }

        return ['sucesso' => $sucesso, 'etapas' => $etapas, 'saida' => $saida];
    }
}
