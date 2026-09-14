<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera voicemail.conf (contexto [telium]) e os parâmetros gerais. */
final class GeradorVoicemail
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        return [
            ...$this->caixas(),
            'voicemail.geral.conf' => $this->geral(),
        ];
    }

    /**
     * Parâmetros do [general].
     *
     * Ficavam presos no template do Ansible: mudar o tamanho máximo de
     * um recado exigia editar arquivo no servidor e rodar o playbook.
     * São ajustes de operação, e operação não pede acesso a SSH.
     */
    private function geral(): string
    {
        $c = Bd::um('SELECT * FROM voicemail_geral WHERE id = 1') ?? [];

        $sim = static fn (mixed $v): string => (int) $v === 1 ? 'yes' : 'no';
        $empresa = (string) (Bd::valor('SELECT nome FROM empresa WHERE id = 1') ?: 'Telium PABX');

        $corpo = trim((string) ($c['corpo'] ?? '')) ?: (
            'Olá ${VM_NAME},\n\nvocê recebeu uma mensagem de ${VM_CALLERID}\n'
            . 'em ${VM_DATE}, com duração de ${VM_DUR}.\n\nA gravação está anexada.\n\n-- '
            . $empresa
        );

        return (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco()
            ->comentario('Incluído dentro do [general] do voicemail.conf estático')
            ->crua('format = ' . ($c['formato'] ?? 'wav49|gsm|wav'))
            ->crua('attach = ' . $sim($c['anexar'] ?? 1))
            ->crua('maxmsg = ' . (int) ($c['max_mensagens'] ?? 100))
            ->crua('maxsecs = ' . (int) ($c['max_segundos'] ?? 180))
            ->crua('minsecs = ' . (int) ($c['min_segundos'] ?? 2))
            ->crua('maxlogins = ' . (int) ($c['max_tentativas'] ?? 3))
            ->crua('saycid = ' . $sim($c['dizer_origem'] ?? 1))
            ->crua('sayduration = ' . $sim($c['dizer_hora'] ?? 1))
            ->crua('delete = ' . $sim($c['apagar_apos_email'] ?? 0))
            ->crua('emailsubject = ' . ($c['assunto'] ?? 'Nova mensagem de voz'))
            ->crua('emailbody = ' . str_replace(["\r\n", "\n"], '\n', $corpo))
            ->texto();
    }

    /** @return array<string,string> */
    private function caixas(): array
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
