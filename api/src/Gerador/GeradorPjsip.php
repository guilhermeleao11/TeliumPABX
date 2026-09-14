<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera pjsip.endpoints.conf e pjsip.trunks.conf. */
final class GeradorPjsip
{
    /** Onde o papel do Asterisk deixa o par TLS trocável pelo console. */
    private const CERT_DIR = '/etc/asterisk/keys';

    /** O par de certificado do Asterisk está legível? */
    private function parDeCertificado(): bool
    {
        return is_readable(self::CERT_DIR . '/asterisk.crt')
            && is_readable(self::CERT_DIR . '/asterisk.key');
    }

    /** @return array<string,string> nome do arquivo => conteúdo */
    public function gerar(): array
    {
        return [
            'pjsip.endpoints.conf' => $this->endpoints(),
            'pjsip.trunks.conf'    => $this->troncos(),
            'pjsip.acl.conf'       => $this->acls(),
        ];
    }

    private function endpoints(): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Personalizações vão em pjsip_custom.conf')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        foreach (Bd::todos('SELECT * FROM ramais WHERE ativo = 1 ORDER BY numero') as $r) {
            $this->endpoint($b, $r);
        }

        return $b->texto();
    }

    /** Um ramal: endpoint, auth e aor. */
    private function endpoint(Bloco $b, array $r): void
    {
        $n = (string) $r['numero'];
        $sim = static fn (mixed $v): string => ((int) $v === 1 ? 'yes' : 'no');
        $webrtc = (int) $r['webrtc'] === 1;

        // Com WebRTC ligado, o Asterisk exige o pacote inteiro: AVPF, ICE,
        // rtcp-mux e DTLS. Deixar qualquer um de fora dá chamada que conecta
        // e não tem áudio, que é o pior jeito de descobrir o problema.
        $avpf     = $webrtc || (int) $r['avpf'] === 1;
        $ice      = $webrtc || (int) $r['ice'] === 1;
        $mux      = $webrtc || (int) $r['rtcp_mux'] === 1;
        $dtls     = $webrtc || (int) $r['dtls'] === 1;
        $transporte = 'transport-' . ($webrtc ? 'wss' : (string) $r['transporte']);

        $b->comentario(str_repeat('-', 62))
          ->comentario("Ramal {$n} — {$r['nome']}" . ($r['setor'] ? " ({$r['setor']})" : '')
              . ($webrtc ? ' — WebRTC' : ''))
          ->comentario(str_repeat('-', 62))
          ->crua("[{$n}]")
          ->crua('type = endpoint')
          ->crua("transport = {$transporte}")
          ->crua('context = ' . ($r['contexto_custom'] ?: $r['contexto']))
          ->crua('disallow = all')
          ->crua('allow = ' . $this->lista((string) $r['codecs']))
          ->crua("auth = {$n}")
          ->crua("aors = {$n}")
          ->crua(sprintf('callerid = "%s" <%s>', $this->limpar((string) $r['nome']), $n))
          ->crua("set_var = TELIUM_PERM={$this->permissoes($r)}")
          ->crua("set_var = TELIUM_RAMAL={$n}")
          // As quatro regras de gravação viajam com o canal, na ordem em
          // que sub-decidir-gravacao as lê: externa recebida, externa
          // feita, interna recebida, interna feita, e a sob demanda.
          ->crua('set_var = TELIUM_GRAV=' . implode('|', [
              $r['grav_ext_entrada'] ?? 'indiferente',
              $r['grav_ext_saida'] ?? 'indiferente',
              $r['grav_int_entrada'] ?? 'indiferente',
              $r['grav_int_saida'] ?? 'indiferente',
              $r['grav_sob_demanda'] ?? 'ativar',
          ]));

        // O número que a operadora vê nas chamadas deste ramal. Sem ele a
        // rota usa o CID do tronco.
        if (($r['cid_pseudo'] ?? '') !== '') {
            $b->crua('set_var = TELIUM_CID=' . $this->limpar((string) $r['cid_pseudo']));
        }

        // Teto de chamadas de saída ao mesmo tempo. É o freio contra a
        // conta gigante quando alguém descobre a senha do ramal.
        if ((int) ($r['max_saidas'] ?? 0) > 0) {
            $b->crua('set_var = TELIUM_MAXSAIDA=' . (int) $r['max_saidas']);
        }

        if (($r['codecs_negados'] ?? '') !== '') {
            $b->crua('disallow = ' . $this->lista((string) $r['codecs_negados']));
        }

        // ---------- sinalização ----------
        $b->crua('dtmf_mode = ' . $this->dtmf((string) $r['dtmf_modo']))
          ->crua('direct_media = ' . $sim($r['direct_media']))
          ->crua('force_rport = ' . $sim($r['forcar_rport']))
          ->crua('rewrite_contact = ' . $sim($r['reescrever_contato']))
          ->crua('rtp_symmetric = ' . $sim($r['rtp_simetrico']))
          ->crua('trust_id_inbound = ' . $sim($r['trust_rpid']))
          ->crua('send_rpid = ' . $sim($r['envia_rpid']))
          ->crua('send_pai = ' . $sim($r['envia_pai']))
          ->crua('send_connected_line = ' . $sim($r['send_connected']))
          ->crua('user_eq_phone = ' . $sim($r['user_eq_phone']))
          ->crua('refer_blind_progress = ' . $sim($r['refer_blind_progress']))
          ->crua('device_state_busy_at = ' . max(1, (int) $r['max_contatos']));

        if ((int) $r['usar_transporte_recebido'] === 1 || $webrtc) {
            $b->crua('use_ptime = no')
              ->crua('media_use_received_transport = yes');
        }

        // ---------- temporizadores ----------
        $b->crua('timers = ' . match ($r['timers_sessao']) {
            'nao'         => 'no',
            'obrigatorio' => 'required',
            default       => 'yes',
        })
          ->crua('timers_sess_expires = ' . max(90, (int) $r['timers_expira']));

        foreach ([
            'rtp_timeout'      => (int) $r['rtp_timeout'],
            'rtp_timeout_hold' => (int) $r['rtp_timeout_hold'],
            'dtls_rekey'       => $dtls ? (int) $r['dtls_rekey'] : 0,
        ] as $opcao => $valor) {
            if ($valor > 0) {
                $b->crua("{$opcao} = {$valor}");
            }
        }

        // ---------- mídia ----------
        $b->crua('max_audio_streams = ' . max(1, (int) $r['max_audio']));
        if ((int) $r['max_video'] > 0) {
            $b->crua('max_video_streams = ' . (int) $r['max_video']);
        }
        if (($r['media_address'] ?? '') !== '') {
            $b->crua("media_address = {$r['media_address']}");
        }
        if ($avpf) {
            $b->crua('use_avpf = yes');
        }
        if ($ice) {
            $b->crua('ice_support = yes');
        }
        if ($mux) {
            $b->crua('rtcp_mux = yes');
        }

        if ($dtls) {
            $b->crua('media_encryption = dtls');
            // Sem certificado no disco o Asterisk recusa o endpoint inteiro
            // e o ramal WebRTC simplesmente some. Nesse caso o próprio
            // Asterisk gera um par efêmero — o DTLS não precisa de CA, a
            // impressão digital viaja no SDP.
            if ($this->parDeCertificado()) {
                // O par é o mesmo do SIP TLS, trocável pelo módulo de certificados.
                $b->crua('dtls_auto_generate_cert = no')
                  ->crua('dtls_cert_file = ' . self::CERT_DIR . '/asterisk.crt')
                  ->crua('dtls_private_key = ' . self::CERT_DIR . '/asterisk.key');
            } else {
                $b->crua('dtls_auto_generate_cert = yes');
            }
            $b->crua("dtls_verify = {$r['dtls_verificar']}")
              ->crua("dtls_setup = {$r['dtls_setup']}");
        } elseif ((int) $r['srtp'] === 1) {
            $b->crua('media_encryption = sdes');
        }
        if ((int) $r['srtp_oportunista'] === 1 && !$dtls) {
            $b->crua('media_encryption_optimistic = yes');
        }

        // ---------- correio de voz ----------
        if ((int) $r['voicemail'] === 1) {
            // "mailboxes", no plural: com "mailbox" o Asterisk não reconhece
            // a opção e descarta o endpoint inteiro — o ramal deixa de existir.
            $b->crua("mailboxes = {$n}@telium")
              ->crua('mwi_subscribe_replaces_unsolicited = '
                  . ($r['mwi_tipo'] === 'nao_solicitado' ? 'no' : 'yes'));

            if ($r['mwi_tipo'] === 'nao_solicitado') {
                $b->crua('aggregate_mwi = ' . $sim($r['mwi_agregado']));
            }
        }

        // ---------- grupos e identificação extra ----------
        foreach ([
            'call_group'       => $r['callgroup'],
            'pickup_group'     => $r['pickupgroup'],
            'accountcode'      => $r['accountcode'],
            'outbound_proxy'   => $r['proxy_saida'],
            'message_context'  => $r['contexto_mensagens'],
        ] as $opcao => $valor) {
            if (($valor ?? '') !== '') {
                $b->crua("{$opcao} = {$valor}");
            }
        }

        // ---------- auth ----------
        $b->branco()
          ->crua("[{$n}]")
          ->crua('type = auth')
          ->crua('auth_type = userpass')
          ->crua("username = {$n}")
          ->crua("password = {$r['senha_sip']}")
          ->branco();

        // ---------- aor ----------
        $b->crua("[{$n}]")
          ->crua('type = aor')
          ->crua('max_contacts = ' . max(1, (int) $r['max_contatos']))
          ->crua('remove_existing = ' . $sim($r['remove_existing']))
          ->crua('qualify_frequency = ' . max(0, (int) $r['qualify_freq']))
          ->crua('qualify_timeout = 3')
          ->crua('maximum_expiration = ' . max(60, (int) $r['expira_max']))
          ->crua('minimum_expiration = ' . max(30, (int) $r['expira_min']));

        // Nada de contact_acl aqui: no PJSIP essa opção não existe no aor
        // nem no endpoint, e escrevê-la derruba o objeto inteiro — o ramal
        // deixa de registrar. Restrição de rede no PJSIP é global, por um
        // objeto type=acl, e é assim que ela sai: uma lista só, montada a
        // partir das redes que os ramais declararam.
        $b->branco();

        // ---------- identificação por alias ou rede ----------
        if (($r['alias_sip'] ?? '') !== '') {
            $b->comentario("alias SIP de {$n}")
              ->crua("[{$n}-alias]")
              ->crua('type = identify')
              ->crua("endpoint = {$n}")
              ->crua("match_header = From: <sip:{$r['alias_sip']}@")
              ->branco();
        }
    }

    /**
     * ACLs nomeadas dos ramais que restringem redes.
     *
     * O endpoint aponta para "telium-<ramal>" com contact_acl; sem este
     * arquivo a referência ficaria pendurada e o registro do ramal seria
     * recusado sem explicação.
     */
    private function acls(): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Redes de onde os ramais podem registrar')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $redes = $this->redesPermitidas();

        if ($redes === []) {
            return $b->comentario('nenhuma restrição de rede declarada')->texto();
        }

        // Uma ACL só, aplicada a todo o SIP. O PJSIP não restringe rede
        // por ramal: quem declara redes no cadastro está dizendo de onde
        // os ramais desta central podem falar, e é isso que sai aqui.
        $b->comentario('União das redes declaradas nos ramais')
          ->crua('[telium-ramais]')
          ->crua('type = acl')
          ->crua('deny = 0.0.0.0/0.0.0.0');

        foreach ($redes as $rede) {
            $b->crua("permit = {$rede}");
        }

        return $b->branco()->texto();
    }

    /**
     * As redes que os ramais declararam, sem repetição.
     *
     * @return string[]
     */
    private function redesPermitidas(): array
    {
        $redes = [];

        $linhas = Bd::todos(
            "SELECT redes_permitidas FROM ramais
              WHERE ativo = 1 AND redes_permitidas IS NOT NULL AND redes_permitidas <> ''"
        );

        foreach ($linhas as $linha) {
            foreach (explode(',', (string) $linha['redes_permitidas']) as $rede) {
                $rede = trim($rede);
                if ($rede !== '' && !in_array($rede, $redes, true)) {
                    $redes[] = $rede;
                }
            }
        }

        return $redes;
    }

    /** "opus, alaw ,ulaw" vira "opus,alaw,ulaw". */
    private function lista(string $bruto): string
    {
        $itens = array_filter(array_map('trim', explode(',', $bruto)), static fn ($x) => $x !== '');

        return implode(',', $itens);
    }

    private function dtmf(string $modo): string
    {
        return match ($modo) {
            'inband'    => 'inband',
            'info'      => 'info',
            'auto'      => 'auto',
            'auto_info' => 'auto_info',
            default     => 'rfc4733',
        };
    }

    private function troncos(): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $troncos = Bd::todos("SELECT * FROM troncos WHERE ativo = 1 AND tipo = 'pjsip' ORDER BY nome");

        foreach ($troncos as $t) {
            $this->tronco($b, $t);
        }

        return $b->texto();
    }

    /**
     * Um tronco: endpoint, aor, auth, identify e registration.
     *
     * Recebe a linha em vez de ler do banco para caber num teste — o que
     * sai daqui com a senha em branco já derrubou tronco em produção.
     *
     * @param array<string,mixed> $t
     */
    public function tronco(Bloco $b, array $t): void
    {
        $nome   = $this->identificador((string) $t['nome']);
        $codecs = implode(',', array_map('trim', explode(',', (string) $t['codecs'])));

        // Usuário sem senha não vira auth: o Asterisk recusa o objeto
        // ("No plain text or digest password found") e tudo que
        // apontar para ele passa a apontar para o nada. O endpoint
        // ficava com outbound_auth de um auth inexistente e a
        // registration morria de vez no primeiro 401 — "Fatal
        // response '401' ... stopping outbound registration" —, um
        // estrago silencioso muito longe da causa. Sem senha, sai um
        // tronco sem autenticação e um comentário dizendo o que
        // falta; a tela de troncos avisa o resto.
        $autentica = (string) $t['usuario'] !== '' && (string) $t['senha'] !== '';

        $b->comentario(str_repeat('-', 62))
          ->comentario("Tronco {$t['nome']} — {$t['host']}:{$t['porta']}")
          ->comentario(str_repeat('-', 62))
          ->crua("[{$nome}]")
          ->crua('type = endpoint')
          ->crua("transport = transport-{$t['transporte']}")
          ->crua("context = {$t['contexto_entrada']}")
          ->crua('disallow = all')
          ->crua("allow = {$codecs}")
          ->crua("aors = {$nome}")
          ->crua('direct_media = no')
          ->crua('rtp_symmetric = yes')
          ->crua('force_rport = yes')
          ->crua('rewrite_contact = yes')
          ->crua("set_var = TELIUM_TRONCO={$t['nome']}");

        if ($t['from_user']) {
            $b->crua("from_user = {$t['from_user']}");
        }
        if ($t['from_domain']) {
            $b->crua("from_domain = {$t['from_domain']}");
        }
        if ($autentica) {
            $b->crua("outbound_auth = {$nome}");
        } elseif ((string) $t['usuario'] !== '') {
            $b->comentario('sem outbound_auth: usuário informado e senha em branco');
        }
        if ($t['cid_saida']) {
            $b->crua(sprintf('callerid = <%s>', $t['cid_saida']));
        }

        $b->branco()
          ->crua("[{$nome}]")
          ->crua('type = aor')
          ->crua("contact = sip:{$t['host']}:{$t['porta']}")
          ->crua('qualify_frequency = 60')
          ->branco();

        if ($autentica) {
            $b->crua("[{$nome}]")
              ->crua('type = auth')
              ->crua('auth_type = userpass')
              ->crua("username = {$t['usuario']}")
              ->crua("password = {$t['senha']}")
              ->branco();
        }

        $b->crua("[{$nome}-identify]")
          ->crua('type = identify')
          ->crua("endpoint = {$nome}")
          ->crua("match = {$t['host']}")
          ->branco();

        if ((int) $t['registrar'] === 1 && $autentica) {
            $b->crua("[{$nome}-reg]")
              ->crua('type = registration')
              ->crua("transport = transport-{$t['transporte']}")
              ->crua("outbound_auth = {$nome}")
              ->crua("server_uri = sip:{$t['host']}:{$t['porta']}")
              ->crua("client_uri = sip:{$t['usuario']}@{$t['host']}")
              // Sem isto o Contact sai como "sip:s@…", e operadora
              // que casa o Contact com a conta recusa o registro.
              ->crua("contact_user = {$t['usuario']}")
              ->crua('retry_interval = 60')
              ->crua('forbidden_retry_interval = 600')
              ->crua('expiration = 3600')
              ->branco();
        }
    }

    /** Lista de classes de discagem liberadas para o ramal. */
    private function permissoes(array $r): string
    {
        $classes = ['emergencia'];                       // emergência é sempre permitida
        foreach (['local' => 'perm_local', 'celular' => 'perm_celular',
                  'ddd' => 'perm_ddd', 'ddi' => 'perm_ddi'] as $classe => $coluna) {
            if ((int) $r[$coluna] === 1) {
                $classes[] = $classe;
            }
        }
        return implode(',', $classes);
    }

    private function identificador(string $nome): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $nome) ?? $nome;
    }

    private function limpar(string $texto): string
    {
        return str_replace(['"', "\n", "\r"], '', $texto);
    }
}
