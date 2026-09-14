<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * Confere se o banco tem o que esta versão do código precisa.
 *
 * O caminho de atualização é "git pull" e depois os scripts de
 * infra/sql. Quem pula o segundo passo encontra a API estourando com
 * SQLSTATE 42S02 no meio de uma tela — o que não diz a ninguém que
 * basta rodar a migração.
 */
final class Esquema
{
    /** Tabelas que o código atual usa. */
    private const TABELAS = [
        'empresa', 'usuarios', 'perfis', 'ramais', 'troncos', 'filas', 'ura',
        'certificados', 'certificado_servicos',
        'destinos_personalizados', 'contatos',
        'codigos_recurso', 'lista_negra', 'lista_permitida',
        'pesquisas', 'pesquisa_respostas',
        'conferencias', 'anuncios',
        'grupos_horario', 'grupo_horario_faixas', 'condicoes_horarias',
        'estacionamentos', 'despertadores',
    ];

    /** Colunas acrescentadas depois, por tabela. */
    private const COLUNAS = [
        'ramais' => ['webrtc', 'siga_me_ativo', 'dtmf_modo', 'dtls', 'grav_ext_entrada'],
        'filas'  => ['callcenter', 'anuncio_entrada_id', 'pesquisa_id'],
        'ura'    => ['anuncio_id'],
        'contatos' => ['empresa', 'discagem_rapida'],
        'codigos_recurso' => ['tipo', 'argumento'],
        // Sem esta coluna o relatório de chamadas não mostra por que
        // a ligação não completou, e o dialplan grava no vazio.
        'cdr' => ['motivo'],
    ];

    /**
     * O que falta no banco.
     *
     * @return array{ok:bool, faltando:string[], mensagem:string}
     */
    public static function conferir(): array
    {
        $faltando = [];

        try {
            $existentes = array_column(
                Bd::todos('SELECT table_name AS t FROM information_schema.tables
                            WHERE table_schema = DATABASE()'),
                't'
            );
            $existentes = array_map('strtolower', $existentes);

            foreach (self::TABELAS as $tabela) {
                if (!in_array($tabela, $existentes, true)) {
                    $faltando[] = "tabela {$tabela}";
                }
            }

            foreach (self::COLUNAS as $tabela => $colunas) {
                if (!in_array($tabela, $existentes, true)) {
                    continue;   // a tabela inteira já foi reportada
                }
                $tem = array_map('strtolower', array_column(
                    Bd::todos('SELECT column_name AS c FROM information_schema.columns
                                WHERE table_schema = DATABASE() AND table_name = ?', [$tabela]),
                    'c'
                ));
                foreach ($colunas as $coluna) {
                    if (!in_array($coluna, $tem, true)) {
                        $faltando[] = "{$tabela}.{$coluna}";
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'faltando' => [],
                    'mensagem' => 'Não foi possível conferir o banco: ' . $e->getMessage()];
        }

        if ($faltando === []) {
            return ['ok' => true, 'faltando' => [], 'mensagem' => 'banco na versão do código'];
        }

        $lista = implode(', ', array_slice($faltando, 0, 6))
               . (count($faltando) > 6 ? ' e mais ' . (count($faltando) - 6) : '');

        return [
            'ok' => false,
            'faltando' => $faltando,
            'mensagem' => "O banco está atrás do código: falta {$lista}. "
                        . 'Rode "php bin/telium migrar" no servidor, ou o playbook do Ansible.',
        ];
    }

    /** A mensagem certa quando um erro de SQL é, na verdade, falta de migração. */
    public static function explicarErro(\Throwable $e): ?string
    {
        $texto = $e->getMessage();

        if (!str_contains($texto, '42S02') && !str_contains($texto, 'Unknown column')) {
            return null;
        }

        return 'O banco está desatualizado para esta versão do código — ' . $texto
             . '. Rode "php bin/telium migrar" no servidor, ou o playbook do Ansible.';
    }
}
