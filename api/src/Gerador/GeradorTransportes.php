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
        return [
            'pjsip.transports.conf' => $this->transportes(),
            'rtp.ice.conf'          => $this->iceDoRtp(),
        ];
    }

    /**
     * Gera o [ice_host_candidates] do rtp.conf.
     *
     * external_media_address conserta o "c=" do SDP, e só. A lista de
     * candidatos ICE é montada à parte, a partir dos endereços das
     * interfaces: numa central atrás de NAT ela sai só com o endereço
     * interno, que o navegador não alcança — o ICE não fecha e o áudio
     * não vai para lugar nenhum, mesmo com o navegador oferecendo um
     * candidato público válido.
     *
     * Este mapa diz "quando anunciar o endereço interno, anuncie o
     * público no lugar". É determinístico: não depende de perguntar a
     * ninguém em tempo de chamada, ao contrário do stunaddr — que, com
     * um endereço que não responde, segura a montagem do RTP por nove
     * segundos antes de a chamada discar.
     */
    private function iceDoRtp(): string
    {
        $publico = Rede::ipPublico();
        $local   = Rede::enderecoLocal();

        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Endereço público: console, Conectividade > Configurações de Rede')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        if ($publico === '' || $local === '' || $local === $publico) {
            $b->comentario('Sem NAT declarado: os candidatos ICE saem como estão.')
              ->comentario('Se o softphone do navegador conecta e não passa áudio,')
              ->comentario('preencha o endereço público no console.');

            return $b->texto();
        }

        return $b->crua('[ice_host_candidates]')
                 ->crua("{$local} => {$publico}")
                 ->branco()
                 ->texto();
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
        // Quem atende o navegador é o nginx, que termina TLS com o
        // certificado do console e repassa em /ws para o servidor HTTP do
        // Asterisk — esse sim preso em 127.0.0.1, no http.conf. Este
        // bloco NÃO abre soquete nenhum: transporte ws/wss no PJSIP é
        // criado por conexão, pelo res_pjsip_transport_websocket.
        //
        // O que o "bind" daqui decide é outra coisa, e é onde o WebRTC
        // morria: na falta de media_address, o Asterisk amarra o soquete
        // de RTP ao endereço deste bind. Com 127.0.0.1 o RTP nascia no
        // loopback e não conseguia mandar nada para fora — nem os testes
        // de conectividade do ICE. O SDP saía correndo bonito, com o
        // endereço da placa, porque os candidatos ICE são levantados das
        // interfaces e não do soquete: a chamada conectava, o navegador
        // mandava dezenas de binding requests e não recebia um único de
        // volta, e o Asterisk enchia o log de "Error sending STUN
        // request: Invalid argument". Conferido no Asterisk 22: com
        // 0.0.0.0 o áudio fecha nos dois sentidos em menos de meio
        // segundo, e continua existindo um único ouvinte na 8090 — o do
        // http.conf, em 127.0.0.1.
        $this->transporte(
            $b,
            'transport-wss',
            'wss',
            '0.0.0.0:' . Rede::portaWs(),
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
