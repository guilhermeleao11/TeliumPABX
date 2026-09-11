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

            // A base interna do Asterisk é o que vale em tempo de chamada
            // para número exato, porque é lá que os códigos *30 e *38
            // escrevem. Aqui ela é reposta a partir do banco, que é o que
            // o console edita — do contrário as duas fontes divergiriam.
            $etapas['listas na base do Asterisk'] = $this->sincronizarListas($ami) ? 'ok' : 'falhou';

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

    /**
     * Repõe as famílias listanegra/ e allowlist/ na base do Asterisk.
     *
     * Só números exatos: padrões (_1199X.) não cabem numa chave de banco
     * e continuam morando no dialplan gerado, que a sub-rotina consulta
     * logo depois.
     */
    private function sincronizarListas(Ami $ami): bool
    {
        $tabelas = [
            'listanegra' => 'SELECT numero FROM lista_negra WHERE ativo = 1',
            'allowlist'  => 'SELECT numero FROM lista_permitida WHERE ativo = 1',
        ];

        $ok = true;

        foreach ($tabelas as $familia => $sql) {
            $resposta = $ami->acao(['Action' => 'DBDelTree', 'Family' => $familia]);
            if (str_contains(strtolower($resposta), 'error')
                && !str_contains(strtolower($resposta), 'not exist')) {
                $ok = false;
            }

            foreach (Bd::todos($sql) as $linha) {
                $numero = (string) $linha['numero'];
                if ($numero === '' || preg_match('/[_.\[\]XZN!]/', $numero) === 1) {
                    continue;   // é padrão de dialplan, não número
                }

                $resposta = $ami->acao([
                    'Action' => 'DBPut',
                    'Family' => $familia,
                    'Key'    => $numero,
                    'Val'    => '1',
                ]);
                if (str_contains(strtolower($resposta), 'error')) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }
}
