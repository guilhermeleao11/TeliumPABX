<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera voicemail.conf (contexto [telium]). */
final class GeradorVoicemail
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco()
            ->crua('[telium]');

        $ramais = Bd::todos(
            'SELECT * FROM ramais WHERE ativo = 1 AND voicemail = 1 ORDER BY numero'
        );

        foreach ($ramais as $r) {
            $opcoes = [
                'attach=' . ((int) $r['vm_email'] === 1 ? 'yes' : 'no'),
                'delete=' . ((int) $r['vm_apagar'] === 1 ? 'yes' : 'no'),
                'tz=brasil',
            ];

            $b->crua(sprintf(
                '%s => %s,%s,%s,,%s',
                $r['numero'],
                $r['vm_senha'] ?: $r['numero'],
                str_replace(',', ' ', (string) $r['nome']),
                (string) $r['email'],
                implode('|', $opcoes)
            ));
        }

        return ['voicemail.conf' => $b->texto()];
    }
}
