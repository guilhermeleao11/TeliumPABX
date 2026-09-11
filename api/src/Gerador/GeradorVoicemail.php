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
            ->comentario('O [general] fica no voicemail.conf estático, que inclui este arquivo')
            ->branco()
            ->crua('[telium]');

        $ramais = Bd::todos(
            'SELECT * FROM ramais WHERE ativo = 1 AND voicemail = 1 ORDER BY numero'
        );

        foreach ($ramais as $r) {
            $opcoes = [
                'attach=' . ((int) $r['vm_email'] === 1 ? 'yes' : 'no'),
                'delete=' . ((int) $r['vm_apagar'] === 1 ? 'yes' : 'no'),
                'saycid=' . ((int) ($r['vm_dizer_origem'] ?? 1) === 1 ? 'yes' : 'no'),
                'envelope=' . ((int) ($r['vm_dizer_hora'] ?? 1) === 1 ? 'yes' : 'no'),
                'maxmsg=' . max(1, (int) ($r['vm_max_mensagens'] ?? 100)),
                'maxsecs=' . max(10, (int) ($r['vm_max_segundos'] ?? 180)),
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
