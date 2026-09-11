<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera pjsip.endpoints.conf e pjsip.trunks.conf. */
final class GeradorPjsip
{
    /** @return array<string,string> nome do arquivo => conteúdo */
    public function gerar(): array
    {
        return [
            'pjsip.endpoints.conf' => $this->endpoints(),
            'pjsip.trunks.conf'    => $this->troncos(),
        ];
    }

    private function endpoints(): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Personalizações vão em pjsip_custom.conf')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $ramais = Bd::todos('SELECT * FROM ramais WHERE ativo = 1 ORDER BY numero');

        foreach ($ramais as $r) {
            $numero    = $r['numero'];
            $transporte = 'transport-' . ($r['transporte'] === 'wss' ? 'wss' : $r['transporte']);
            $codecs    = implode(',', array_map('trim', explode(',', (string) $r['codecs'])));
            $permissoes = $this->permissoes($r);
            $webrtc     = (int) $r['webrtc'] === 1;

            $b->comentario(str_repeat('-', 62))
              ->comentario("Ramal {$numero} — {$r['nome']}" . ($r['setor'] ? " ({$r['setor']})" : ''))
              ->comentario(str_repeat('-', 62))
              ->crua("[{$numero}]")
              ->crua('type = endpoint')
              ->crua("transport = {$transporte}")
              ->crua("context = {$r['contexto']}")
              ->crua('disallow = all')
              ->crua("allow = {$codecs}")
              ->crua("auth = {$numero}")
              ->crua("aors = {$numero}")
              ->crua(sprintf('callerid = "%s" <%s>', $this->limpar((string) $r['nome']), $numero))
              ->crua('direct_media = no')
              ->crua('force_rport = yes')
              ->crua('rewrite_contact = yes')
              ->crua('rtp_symmetric = yes')
              ->crua('dtmf_mode = rfc4733')
              ->crua('device_state_busy_at = ' . max(1, (int) $r['max_contatos']))
              ->crua("set_var = TELIUM_PERM={$permissoes}")
              ->crua("set_var = TELIUM_RAMAL={$numero}");

            if ((int) $r['voicemail'] === 1) {
                // "mailboxes", no plural: com "mailbox" o Asterisk não
                // reconhece a opção e descarta o endpoint inteiro — o ramal
                // simplesmente não existe.
                $b->crua("mailboxes = {$numero}@telium");
            }
            if ($r['callgroup']) {
                $b->crua("call_group = {$r['callgroup']}");
            }
            if ($r['pickupgroup']) {
                $b->crua("pickup_group = {$r['pickupgroup']}");
            }
            if ($webrtc) {
                // Caminho alternativo: navegador falando direto com o Asterisk.
                $b->crua('webrtc = yes');
            } elseif ((int) $r['srtp'] === 1) {
                $b->crua('media_encryption = sdes');
            }

            $b->branco()
              ->crua("[{$numero}]")
              ->crua('type = auth')
              ->crua('auth_type = userpass')
              ->crua("username = {$numero}")
              ->crua("password = {$r['senha_sip']}")
              ->branco()
              ->crua("[{$numero}]")
              ->crua('type = aor')
              ->crua('max_contacts = ' . max(1, (int) $r['max_contatos']))
              ->crua('remove_existing = yes')
              ->crua('qualify_frequency = 60')
              ->crua('qualify_timeout = 3')
              ->branco();
        }

        return $b->texto();
    }

    private function troncos(): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $troncos = Bd::todos("SELECT * FROM troncos WHERE ativo = 1 AND tipo = 'pjsip' ORDER BY nome");

        foreach ($troncos as $t) {
            $nome   = $this->identificador((string) $t['nome']);
            $codecs = implode(',', array_map('trim', explode(',', (string) $t['codecs'])));

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
            if ($t['usuario']) {
                $b->crua("outbound_auth = {$nome}");
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

            if ($t['usuario']) {
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

            if ((int) $t['registrar'] === 1 && $t['usuario']) {
                $b->crua("[{$nome}-reg]")
                  ->crua('type = registration')
                  ->crua("transport = transport-{$t['transporte']}")
                  ->crua("outbound_auth = {$nome}")
                  ->crua("server_uri = sip:{$t['host']}:{$t['porta']}")
                  ->crua("client_uri = sip:{$t['usuario']}@{$t['host']}")
                  ->crua('retry_interval = 60')
                  ->crua('forbidden_retry_interval = 600')
                  ->crua('expiration = 3600')
                  ->branco();
            }
        }

        return $b->texto();
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
