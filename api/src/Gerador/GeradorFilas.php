<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera queues.conf. */
final class GeradorFilas
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        foreach (Bd::todos('SELECT * FROM filas WHERE ativo = 1 ORDER BY numero') as $f) {
            $b->comentario("Fila {$f['numero']} — {$f['nome']}")
              ->crua("[{$f['numero']}]")
              ->crua("strategy = {$f['estrategia']}")
              ->crua("timeout = {$f['timeout_agente']}")
              ->crua("retry = {$f['retry']}")
              ->crua("wrapuptime = {$f['wrapuptime']}")
              ->crua("musicclass = {$f['musica_espera']}")
              ->crua('announce-position = ' . ((int) $f['anuncio_posicao'] === 1 ? 'yes' : 'no'))
              ->crua('announce-frequency = 30')
              ->crua('joinempty = yes')
              ->crua('leavewhenempty = no')
              ->crua('ringinuse = no')
              ->crua("servicelevel = {$f['sla_segundos']}");

            if ((int) $f['gravar'] === 1) {
                $b->crua('monitor-type = MixMonitor')
                  ->crua('monitor-format = wav');
            }

            $agentes = Bd::todos(
                'SELECT r.numero, r.nome, a.penalidade
                   FROM fila_agentes a
                   JOIN ramais r ON r.id = a.ramal_id
                  WHERE a.fila_id = ? AND a.tipo = ?
               ORDER BY a.penalidade, r.numero',
                [$f['id'], 'estatico']
            );

            foreach ($agentes as $a) {
                $b->crua(sprintf(
                    'member => PJSIP/%s,%d,%s',
                    $a['numero'],
                    (int) $a['penalidade'],
                    str_replace(',', ' ', (string) $a['nome'])
                ));
            }

            $b->branco();
        }

        return ['queues.conf' => $b->texto()];
    }
}
