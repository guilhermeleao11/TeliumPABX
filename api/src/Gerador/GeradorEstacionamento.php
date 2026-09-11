<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Gera res_parking.conf.
 *
 * Um lote por linha da tabela. O lote marcado como padrão é o que os
 * códigos *85 e *86 usam, porque Park() sem argumento cai nele.
 */
final class GeradorEstacionamento
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $lotes = Bd::todos('SELECT * FROM estacionamentos WHERE ativo = 1 ORDER BY padrao DESC, nome');

        if ($lotes === []) {
            return ['res_parking.conf' => $b->comentario('nenhum lote de estacionamento ativo')->texto()];
        }

        foreach ($lotes as $l) {
            // O Asterisk chama de "default" o lote que o Park() usa sem
            // argumento; renomear isso quebraria os códigos de recurso.
            $nome = (int) $l['padrao'] === 1 ? 'default' : (string) $l['nome'];

            $b->comentario("{$l['nome']}" . ((int) $l['padrao'] === 1 ? ' (padrão)' : ''))
              // Sem "type" aqui: o res_parking.conf não tem essa opção, e
              // o Asterisk descarta o lote inteiro se ela aparecer.
              ->crua("[{$nome}]")
              ->crua("parkext = {$l['numero_estacionar']}")
              ->crua("parkpos = {$l['vaga_inicio']}-{$l['vaga_fim']}")
              ->crua('context = telium-vagas-' . $nome)
              ->crua("parkingtime = {$l['tempo_segundos']}")
              ->crua("parkedmusicclass = {$l['musica_espera']}")
              ->crua('findslot = ' . ((int) $l['primeira_vaga_livre'] === 1 ? 'first' : 'next'))
              ->crua('comebacktoorigin = ' . ((int) $l['volta_para_origem'] === 1 ? 'yes' : 'no'))
              ->crua("comebackdialtime = {$l['tempo_volta']}");

            if ((int) $l['volta_para_origem'] !== 1) {
                $b->crua('comebackcontext = telium-volta-' . $nome);
            }

            // O tom avisa a vaga a quem estacionou; sem ele, ninguém sabe
            // para onde a chamada foi.
            if ((int) $l['avisar_vaga'] === 1) {
                $b->crua('courtesytone = beep')
                  ->crua('parkedplay = caller');
            }

            // Quem está na vaga não deveria conseguir transferir nem
            // desligar a chamada de outra pessoa.
            $b->crua('parkedcalltransfers = caller')
              ->crua('parkedcallreparking = caller')
              ->crua('parkedcallhangup = no')
              ->crua('parkedcallrecording = caller')
              ->branco();
        }

        return ['res_parking.conf' => $b->texto()];
    }
}
