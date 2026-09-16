<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Dominio\Rede;

/**
 * Gera pjsip.transports.conf — por onde o Asterisk fala SIP.
 *
 * Os transportes moravam no pjsip.conf estático, escrito pelo
 * instalador. Quem instalasse atrás de NAT e descobrisse depois — que é
 * como se descobre, olhando um REGISTER sair com IP interno — só tinha o
 * caminho de editar variável do Ansible e rodar o playbook de novo.
 * Agora o endereço público e as faixas locais vêm do banco, e trocá-los
 * é aplicar configuração como qualquer outra coisa.
 *
 * O que não se muda por aqui é a porta de escuta: o Asterisk avisa que
 * "transport não é totalmente recarregável" e mantém o bind anterior.
 * external_media_address, external_signaling_address e local_net, que
 * são justamente os de NAT, recarregam sem reiniciar — conferido no
 * Asterisk 22.
 */
final class GeradorTransportes
{
    /** Onde o papel do Asterisk deixa o par TLS trocável pelo console. */
    private const CERT_DIR = '/etc/asterisk/keys';

    /** @return array<string,string> */
    public function gerar(): array
    {
        return ['pjsip.transports.conf' => $this->transportes()];
    }

    private function transportes(): string
    {
        $publico = Rede::ipPublico();
        $locais  = Rede::redesLocais();

        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Endereço público e faixas locais: console, Conectividade > Configurações de Rede')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        if ($publico === '') {
            $b->comentario('Sem endereço público configurado: a central anuncia o endereço')
              ->comentario('da própria placa. Correto em rede com IP público direto; atrás de')
              ->comentario('NAT, é o que faz o tronco nunca registrar.')
              ->branco();
        }

        $this->transporte($b, 'transport-udp', 'udp', '0.0.0.0:' . Rede::portaSip(), $publico, $locais);
        $this->transporte($b, 'transport-tcp', 'tcp', '0.0.0.0:' . Rede::portaSip(), $publico, $locais);

        // SIP TLS na 5061. O par vem do módulo de certificados do console;
        // até alguém enviar um, é o autoassinado de fábrica — o suficiente
        // para cifrar a sinalização, mas o telefone vai pedir para confiar.
        $this->transporte(
            $b,
            'transport-tls',
            'tls',
            '0.0.0.0:' . Rede::portaSipTls(),
            $publico,
            $locais,
            [
                'cert_file'     => self::CERT_DIR . '/asterisk.crt',
                'priv_key_file' => self::CERT_DIR . '/asterisk.key',
                'method'        => 'tlsv1_2',
            ]
        );

        // WebRTC: o navegador fala direto com o Asterisk.
        //
        // Fica preso em 127.0.0.1 porque quem atende o navegador é o
        // nginx, que já termina TLS com o certificado do console e
        // repassa em /ws. A sinalização chega por ali, mas a mídia sai
        // daqui direto para o navegador: sem o endereço público no SDP,
        // o áudio some quando o servidor está atrás de NAT.
        $this->transporte(
            $b,
            'transport-wss',
            'wss',
            '127.0.0.1:' . Rede::portaWs(),
            $publico,
            $locais
        );

        return $b->texto();
    }

    /**
     * @param list<string>          $locais
     * @param array<string,string>  $extras
     */
    private function transporte(
        Bloco $b,
        string $nome,
        string $protocolo,
        string $bind,
        string $publico,
        array $locais,
        array $extras = []
    ): void {
        $b->crua("[{$nome}]")
          ->crua('type = transport')
          ->crua("protocol = {$protocolo}")
          ->crua("bind = {$bind}");

        foreach ($extras as $chave => $valor) {
            $b->crua("{$chave} = {$valor}");
        }

        foreach ($locais as $faixa) {
            $b->crua("local_net = {$faixa}");
        }

        if ($publico !== '') {
            $b->crua("external_media_address = {$publico}")
              ->crua("external_signaling_address = {$publico}");
        }

        $b->branco();
    }
}
