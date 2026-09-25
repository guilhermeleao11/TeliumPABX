<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Dominio\Rede;
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

        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco()
            ->comentario('Incluído dentro do [general] do voicemail.conf estático');

        // O remetente do recado é o MESMO que o console testa.
        //
        // O voicemail.conf estático traz "pabx@<hostname>", que nunca foi
        // configurado por ninguém. Com Gmail ou Microsoft 365 isso é
        // recusado na hora — o servidor só aceita enviar com o endereço
        // da conta autenticada —, e o efeito era o pior possível: o teste
        // do console passava, porque ele usa o remetente certo, e o
        // recado do correio de voz não chegava nunca, falhando só no log
        // do Asterisk. Como este arquivo é incluído DEPOIS, o que está
        // aqui prevalece.
        $smtp = Bd::um('SELECT ativo, remetente, nome_remetente FROM smtp WHERE id = 1');
        $remetente = trim((string) ($smtp['remetente'] ?? ''));

        // Escrito SEMPRE, e só aqui. Enquanto o voicemail.conf estático
        // também trazia estas duas linhas, era o valor de lá que valia —
        // o app_voicemail fica com a primeira ocorrência dentro do
        // [general], e o include vem depois.
        if ((int) ($smtp['ativo'] ?? 0) !== 1 || $remetente === '') {
            // Sem envio configurado, o endereço não vai a lugar nenhum:
            // vale o nome da central, que é o que o Asterisk fazia antes.
            $dominio = Rede::dominioSip() ?: 'localhost';
            $remetente = "pabx@{$dominio}";
        }

        $b->crua("serveremail = {$remetente}")
          ->crua('fromstring = ' . (trim((string) ($smtp['nome_remetente'] ?? '')) ?: $empresa));

        return $b
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
