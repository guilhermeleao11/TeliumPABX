<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Gera confbridge.conf.
 *
 * Cada sala vira três perfis: a ponte (limite de gente, gravação), o
 * participante comum e o administrador — que é quem consegue travar a
 * sala, expulsar e, quando a sala espera por ele, liberar a conversa.
 *
 * O menu de teclas é um só para todas as salas: o que muda de uma sala
 * para outra é quem entra, não o que as teclas fazem.
 */
final class GeradorConferencias
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->comentario('Personalizações vão em telium/confbridge_custom.conf')
            ->branco();

        $this->menu($b);

        $salas = Bd::todos('SELECT * FROM conferencias WHERE ativo = 1 ORDER BY numero');

        if ($salas === []) {
            $b->comentario('nenhuma sala de conferência ativa');
        }

        foreach ($salas as $s) {
            $this->sala($b, $s);
        }

        $b->crua('#include "telium/confbridge_custom.conf"');

        return ['confbridge.conf' => $b->texto()];
    }

    /**
     * O menu de teclas, igual em todas as salas.
     *
     * O `type` vai numa linha própria: os parênteses depois do nome da
     * seção são herança de template para o Asterisk, não declaração de
     * tipo — escrever [x](type=menu) faz ele procurar um template
     * chamado "type=menu" e desistir do perfil inteiro.
     */
    private function menu(Bloco $b): void
    {
        $b->comentario('Teclas dentro da conferência')
          ->crua('[telium-menu]')
          ->crua('type = menu')
          ->crua('* = playback_and_continue(conf-usermenu)')
          ->crua('1 = toggle_mute')
          ->crua('4 = decrease_listening_volume')
          ->crua('6 = increase_listening_volume')
          ->crua('7 = decrease_talking_volume')
          ->crua('9 = increase_talking_volume')
          ->crua('0 = participant_count')
          ->branco()
          ->comentario('Teclas de quem administra a sala')
          ->crua('[telium-menu-admin]')
          ->crua('type = menu')
          ->crua('* = playback_and_continue(conf-adminmenu)')
          ->crua('1 = toggle_mute')
          ->crua('2 = admin_toggle_conference_lock')
          ->crua('3 = admin_kick_last')
          ->crua('4 = decrease_listening_volume')
          ->crua('6 = increase_listening_volume')
          ->crua('7 = decrease_talking_volume')
          ->crua('9 = increase_talking_volume')
          ->crua('0 = participant_count')
          ->branco();
    }

    private function sala(Bloco $b, array $s): void
    {
        $n = (string) $s['numero'];
        $sim = static fn (mixed $v): string => ((int) $v === 1 ? 'yes' : 'no');

        $b->comentario("Sala {$n} — {$s['nome']}")
          ->crua("[sala-{$n}]")
          ->crua('type = bridge')
          ->crua('internal_sample_rate = auto')
          ->crua('mixing_interval = 20')
          ->crua('video_mode = none');

        if ((int) $s['max_usuarios'] > 0) {
            $b->crua("max_members = {$s['max_usuarios']}");
        }

        // A gravação é ligada no dialplan, que é quem sabe montar o nome
        // do arquivo com a data — aqui só fica o que é fixo.
        $b->crua('record_conference = no')
          ->branco();

        $b->crua("[sala-{$n}-participante]")
          ->crua('type = user')
          ->crua('announce_user_count = ' . $sim($s['anunciar_quantidade']))
          ->crua('announce_join_leave = ' . $sim($s['anunciar_entrada_saida']))
          ->crua('announce_user_count_all = ' . $sim($s['anunciar_quantidade']))
          ->crua('music_on_hold_when_empty = ' . $sim($s['musica_sozinho']))
          ->crua('startmuted = ' . $sim($s['silenciar_ao_entrar']))
          ->crua('wait_marked = ' . $sim($s['esperar_admin']))
          ->crua('end_marked = ' . $sim($s['esperar_admin']))
          ->crua('dtmf_passthrough = no')
          ->crua('quiet = no')
          ->branco();

        $b->crua("[sala-{$n}-admin]")
          ->crua('type = user')
          ->crua('admin = yes')
          ->crua('marked = yes')
          ->crua('announce_user_count = ' . $sim($s['anunciar_quantidade']))
          ->crua('announce_join_leave = ' . $sim($s['anunciar_entrada_saida']))
          ->crua('music_on_hold_when_empty = ' . $sim($s['musica_sozinho']))
          ->crua('dtmf_passthrough = no')
          ->crua('quiet = no')
          ->branco();
    }
}
