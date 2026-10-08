<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Códigos de recurso: o contexto telium-recursos e o featuremap.
 *
 * O código discado é configurável; o que ele faz é fixo e mora aqui,
 * indexado pela chave. Quem precisa de um argumento (um ramal, um
 * destino, uma fila) vira um padrão `_<codigo>X.` e lê o resto com
 * ${EXTEN:n}; os demais são extensões exatas.
 *
 * O estado por ramal (não perturbe, desvios, siga-me, espera) fica na
 * base interna do Asterisk, que sobrevive a reload e é o que as
 * sub-rotinas do extensions.conf consultam em tempo de chamada.
 */
final class GeradorRecursos
{
    /** Chaves que vivem no featuremap, não no dialplan. */
    private const FEATUREMAP = [
        'desconectar'    => 'disconnect',
        'gravar_alterna' => 'automixmon',
        'atxfer'         => 'atxfer',
        'blindxfer'      => 'blindxfer',
    ];

    /** @return array<string,string> arquivo => conteúdo */
    public function gerar(): array
    {
        $codigos = Bd::todos(
            'SELECT * FROM codigos_recurso WHERE ativo = 1 ORDER BY categoria, ordem, codigo'
        );

        return [
            'extensions.recursos.conf' => $this->dialplan($codigos),
            'features.conf'            => $this->featuremap($codigos),
        ];
    }

    // ------------------------------------------------------------------
    private function dialplan(array $codigos): string
    {
        $b = $this->cabecalho('Contexto: códigos de recurso')->contexto('telium-recursos');
        $escritos = 0;
        $this->filasCallCenter = array_map('strval', array_column(
            Bd::todos('SELECT numero FROM filas WHERE ativo = 1 AND callcenter = 1'), 'numero'
        ));

        foreach ($codigos as $c) {
            if (($c['tipo'] ?? 'dialplan') !== 'dialplan') {
                continue;
            }

            $chave = (string) $c['chave'];
            $codigo = (string) $c['codigo'];
            $passos = $this->passos($chave, $codigo);

            if ($passos === null) {
                continue;
            }

            $b->branco()->comentario("{$codigo} — {$c['nome']}");
            if (($c['descricao'] ?? '') !== '') {
                $b->comentario('  ' . $c['descricao']);
            }

            [$exten, $linhas, $rotulos] = [$passos[0], $passos[1], $passos[2] ?? []];
            $b->exten($exten, array_shift($linhas))->apps($linhas);

            // Cada desvio nomeado vira um rótulo logo abaixo do fluxo principal.
            foreach ($rotulos as $rotulo => $bloco) {
                $b->same(array_shift($bloco), $rotulo)->apps($bloco);
            }

            $escritos++;
        }

        $escritos += $this->pausas($b);
        $escritos += $this->codigosDasCaixas($b);

        if ($escritos === 0) {
            $b->comentario('nenhum código de recurso ativo');
        }

        $this->caixas($b);

        return $b->texto();
    }

    /** O código de cada caixa postal avulsa: abre a caixa para ouvir os recados. */
    private function codigosDasCaixas(Bloco $b): int
    {
        $n = 0;
        foreach ($this->caixasAtivas() as $c) {
            $codigo = (string) ($c['codigo'] ?? '');
            if (preg_match('/^[*#][0-9]{1,6}$/', $codigo) !== 1) {
                continue;
            }
            // "s" pula a senha: é a caixa que qualquer ramal pode ouvir.
            $opcoes = (int) $c['pedir_senha'] === 1 ? '' : ',s';
            $b->branco()->comentario("{$codigo} — Ouvir a caixa postal {$c['numero']} ({$c['nome']})")
              ->exten($codigo, "NoOp(Caixa postal {$c['numero']})")
              ->apps(['Answer()', 'Wait(1)', "VoiceMailMain({$c['numero']}@telium{$opcoes})", 'Hangup()']);
            $n++;
        }

        return $n;
    }

    /**
     * telium-caixas: para onde o destino "caixa postal" manda a chamada.
     *
     * Com anúncio, ele é a saudação e o "s" pula a do correio; sem anúncio,
     * vale a saudação gravada pelo menu da caixa (ou a padrão do Asterisk).
     */
    private function caixas(Bloco $b): void
    {
        $b->branco()->comentario('Caixas postais sem ramal: o recado')->contexto('telium-caixas');
        $caixas = $this->caixasAtivas();
        if ($caixas === []) {
            $b->comentario('nenhuma caixa postal avulsa ativa');

            return;
        }
        foreach ($caixas as $c) {
            $saudacao = Som::prompt($c['anuncio'] ?? null);
            $b->exten((string) $c['numero'], "NoOp(Recado na caixa {$c['numero']} — {$c['nome']})")
              ->apps(array_merge(
                  ['Answer()', 'Wait(1)'],
                  $saudacao !== '' ? ["Playback({$saudacao})", "VoiceMail({$c['numero']}@telium,s)"]
                                   : ["VoiceMail({$c['numero']}@telium,u)"],
                  ['Hangup()']
              ));
        }
    }

    /** @var list<array<string,mixed>>|null */
    private ?array $caixasAtivas = null;

    /** As caixas avulsas que valem: ativas e com número que não é de ramal. */
    private function caixasAtivas(): array
    {
        return $this->caixasAtivas ??= Bd::todos(
            'SELECT c.*, s.arquivo AS anuncio
               FROM caixas_postais c
          LEFT JOIN anuncios a ON a.id = c.anuncio_id AND a.ativo = 1
          LEFT JOIN audios s ON s.id = a.audio_id
              WHERE c.ativo = 1 AND c.numero NOT IN (SELECT numero FROM ramais)
           ORDER BY c.numero'
        );
    }

    /**
     * Um código por motivo de pausa do call center: discou, pausou por ele.
     *
     * O código leva o id do motivo, e não o nome: renomear a pausa não
     * pede para aplicar a configuração de novo.
     */
    private function pausas(Bloco $b): int
    {
        $pausas = Bd::todos(
            "SELECT id, nome, codigo FROM cc_pausas_motivos
              WHERE ativo = 1 AND sistema = 0 AND codigo IS NOT NULL AND codigo <> ''
           ORDER BY ordem, nome"
        );

        foreach ($pausas as $p) {
            $codigo = (string) $p['codigo'];
            if (preg_match('/^[*#][0-9]{1,6}$/', $codigo) !== 1) {
                continue;
            }
            $b->branco()->comentario("{$codigo} — Call center: pausa \"{$p['nome']}\"")
              ->exten($codigo, "NoOp(Call center: pausa {$p['id']})")
              ->apps(["GoSub(telium-cc-telefone,pausa,1({$p['id']}))", 'Hangup()']);
        }

        return count($pausas);
    }

    /**
     * As filas de call center, que *45 e *46 não alcançam.
     *
     * Nelas quem atende é o agente: entrar pelo código genérico punha o
     * ramal na fila sem agente nenhum — fora do monitor, do relatório e da
     * tabulação — e pausar por ele pausava sem motivo.
     *
     * @var list<string>
     */
    private array $filasCallCenter = [];

    /** O GotoIf que desvia a fila de call center para o aviso. */
    private function recusaCallCenter(string $arg): ?string
    {
        if ($this->filasCallCenter === []) {
            return null;
        }
        $lista = implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), $this->filasCallCenter));

        return 'GotoIf($[${REGEX("^(' . $lista . ')$" ' . $arg . ')}]?callcenter)';
    }

    /** @return array<string,string[]> o rótulo que *45 e *46 usam numa fila de call center */
    private function avisoCallCenter(): array
    {
        return $this->filasCallCenter === [] ? [] : ['callcenter' => [
            "NoOp(Fila de call center: entra-se e pausa-se pelos códigos do agente)",
            'Playback(telium-cc/operacao-falhou)', 'Hangup()',
        ]];
    }

    private function featuremap(array $codigos): string
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Códigos apertados durante a chamada')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco()
            ->contexto('featuremap');

        $achou = false;
        foreach ($codigos as $c) {
            $alvo = self::FEATUREMAP[$c['chave']] ?? null;
            if ($alvo === null || ($c['tipo'] ?? '') !== 'featuremap') {
                continue;
            }
            $b->crua(sprintf('%-11s => %s   ; %s', $alvo, $c['codigo'], $c['nome']));
            $achou = true;
        }

        if (!$achou) {
            $b->comentario('nenhum código de chamada ativo — vale o featuremap do features.conf');
        }

        return $b->texto();
    }

    /**
     * O que cada chave faz.
     *
     * @return array{0:string, 1:string[], 2?:array<string,string[]>}|null
     *         [extensão, aplicações, blocos com rótulo] — null se a chave
     *         não é conhecida por esta versão do gerador
     */
    private function passos(string $chave, string $codigo): ?array
    {
        $n = strlen($codigo);
        $arg = '${EXTEN:' . $n . '}';           // o que foi discado depois do código
        $eu = '${CALLERID(num)}';               // ramal de quem discou
        // X! e não X.: o ponto exige dois dígitos ou mais depois do código,
        // e condição horária 1 a 9, discagem rápida de um dígito ou vaga 1
        // a 9 não casavam com nada — o *271 do feriado dava inválido.
        $comArg = "_{$codigo}X!";

        return match ($chave) {
            // ---------------- informações ----------------
            'eco' => [$codigo, [
                'NoOp(Teste de eco)', 'Answer()', 'Wait(1)',
                'Playback(demo-echotest)', 'Echo()', 'Hangup()',
            ]],
            'hora' => [$codigo, [
                'NoOp(Hora certa)', 'Answer()', 'Wait(1)',
                'SayUnixTime(,${TELIUM_TZ},HM)', 'Hangup()',
            ]],
            'meu_ramal' => [$codigo, [
                'NoOp(Falar o ramal)', 'Answer()', 'Wait(1)',
                "SayDigits({$eu})", 'Hangup()',
            ]],
            'rastrear' => [$codigo, [
                'NoOp(Último chamador)', 'Answer()', 'Wait(1)',
                // O cadastro do ramal decide quem pode saber o número de
                // quem ligou por último: em atendimento ao público isso
                // costuma ser liberado, em ramal de uso comum, não.
                'GotoIf($[${DB_EXISTS(rastreio-nao/' . $eu . ')}]?barrado)',
                'Set(TELIUM_ULT=${DB(ultimochamador/' . $eu . ')})',
                'GotoIf($["${TELIUM_ULT}" = ""]?nada)',
                'SayDigits(${TELIUM_ULT})', 'Hangup()',
            ], ['barrado' => [
                'NoOp(Este ramal não tem rastreio de chamada liberado)',
                'Playback(cannot-complete-as-dialed)', 'Hangup()',
            ], 'nada' => [
                'NoOp(Nenhuma chamada registrada para este ramal)',
                'Playback(pbx-invalid)', 'Hangup()',
            ]]],
            'diretorio' => [$codigo, [
                'NoOp(Diretório por nome)', 'Answer()',
                'Directory(telium,interno,f)', 'Hangup()',
            ]],

            // ---------------- correio de voz ----------------
            'vm_proprio' => [$codigo, [
                'NoOp(Correio de voz próprio)', 'Answer()',
                "VoiceMailMain({$eu}@telium)", 'Hangup()',
            ]],
            'vm_outro' => [$codigo, [
                'NoOp(Correio de voz de outro ramal)', 'Answer()',
                'VoiceMailMain(@telium)', 'Hangup()',
            ]],
            'vm_direto' => [$comArg, [
                "NoOp(Recado direto para {$arg})", 'Answer()',
                "VoiceMail({$arg}@telium,u)", 'Hangup()',
            ]],

            // ---------------- captura e estacionamento ----------------
            'captura' => [$codigo, ['NoOp(Captura de grupo)', 'Pickup()', 'Hangup()']],
            'captura_dir' => [$comArg, [
                "NoOp(Captura direta de {$arg})",
                "Pickup({$arg}@PICKUPMARK)",
                "PickupChan(PJSIP/{$arg})",
                'Hangup()',
            ]],
            'estacionar' => [$codigo, [
                'NoOp(Estacionar a chamada)', 'Park()', 'Hangup()',
            ]],
            'estacionar_captura' => [$comArg, [
                "NoOp(Retomar a vaga {$arg})",
                "ParkedCall(default,{$arg})",
                'Hangup()',
            ]],

            // ---------------- não perturbe ----------------
            // Não perturbe, chamada em espera e interfonia gravam também no
            // banco: o "aplicar" repõe a base do Asterisk a partir dele, e
            // o que só estava na base sumia na próxima aplicação.
            'dnd_ligar' => [$codigo, [
                'NoOp(Ativar não perturbe)',
                "Set(DB(dnd/{$eu})=1)",
                "Set(ODBC_TELIUM_DND({$eu})=1)",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'dnd_desligar' => [$codigo, [
                'NoOp(Desativar não perturbe)',
                'NoOp(${DB_DELETE(dnd/' . $eu . ')})',
                "Set(ODBC_TELIUM_DND({$eu})=0)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'dnd_alterna' => [$codigo, [
                'NoOp(Alternar não perturbe)',
                'GotoIf($[${DB_EXISTS(dnd/' . $eu . ')}]?desliga)',
                "Set(DB(dnd/{$eu})=1)",
                "Set(ODBC_TELIUM_DND({$eu})=1)",
                'GoSub(sub-confirma,s,1(activated))',
            ], ['desliga' => [
                'NoOp(${DB_DELETE(dnd/' . $eu . ')})',
                "Set(ODBC_TELIUM_DND({$eu})=0)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]]],

            // ---------------- chamada em espera ----------------
            'cw_on' => [$codigo, [
                'NoOp(Ativar chamada em espera)',
                "Set(DB(cw/{$eu})=1)",
                "Set(ODBC_TELIUM_CW({$eu})=1)",
                'GoSub(sub-confirma,s,1(call-waiting))',
            ]],
            'cw_off' => [$codigo, [
                'NoOp(Desativar chamada em espera)',
                'NoOp(${DB_DELETE(cw/' . $eu . ')})',
                "Set(ODBC_TELIUM_CW({$eu})=0)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'cw_alterna' => [$codigo, [
                'NoOp(Alternar chamada em espera)',
                'GotoIf($[${DB_EXISTS(cw/' . $eu . ')}]?desliga)',
                "Set(DB(cw/{$eu})=1)",
                "Set(ODBC_TELIUM_CW({$eu})=1)",
                'GoSub(sub-confirma,s,1(activated))',
            ], ['desliga' => [
                'NoOp(${DB_DELETE(cw/' . $eu . ')})',
                "Set(ODBC_TELIUM_CW({$eu})=0)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]]],

            // ---------------- desvios ----------------
            'cf_todas_on'      => $this->desvioLiga('cf', $comArg, $arg, $eu, 'de todas'),
            'cf_todas_off'     => $this->desvioDesliga('cf', $codigo, $eu, 'de todas'),
            'cf_todas_pedir'   => $this->desvioPergunta('cf', $codigo, $eu, 'de todas'),
            'cf_todas_alterna' => $this->desvioAlterna('cf', $codigo, $eu, 'de todas'),

            'cf_ocupado_on'      => $this->desvioLiga('cfb', $comArg, $arg, $eu, 'por ocupado'),
            'cf_ocupado_off'     => $this->desvioDesliga('cfb', $codigo, $eu, 'por ocupado'),
            'cf_ocupado_pedir'   => $this->desvioPergunta('cfb', $codigo, $eu, 'por ocupado'),
            'cf_ocupado_alterna' => $this->desvioAlterna('cfb', $codigo, $eu, 'por ocupado'),

            'cf_naoatende_on'      => $this->desvioLiga('cfu', $comArg, $arg, $eu, 'por não atender'),
            'cf_naoatende_off'     => $this->desvioDesliga('cfu', $codigo, $eu, 'por não atender'),
            'cf_naoatende_pedir'   => $this->desvioPergunta('cfu', $codigo, $eu, 'por não atender'),
            'cf_naoatende_alterna' => $this->desvioAlterna('cfu', $codigo, $eu, 'por não atender'),

            // ---------------- siga-me ----------------
            'sigame_ligar' => [$comArg, [
                "NoOp(Siga-me para {$arg})",
                "Set(DB(sigame/{$eu})={$arg})",
                "Set(DB(sigame-ultimo/{$eu})={$arg})",
                // O console mostra o que o ramal configurou pelo telefone.
                "Set(ODBC_TELIUM_SIGAME({$eu})={$arg})",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'sigame_desligar' => [$codigo, [
                'NoOp(Desativar siga-me)',
                'NoOp(${DB_DELETE(sigame/' . $eu . ')})',
                "Set(ODBC_TELIUM_SIGAME({$eu})=)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'sigame_alterna' => [$codigo, [
                'NoOp(Alternar siga-me)',
                'GotoIf($[${DB_EXISTS(sigame/' . $eu . ')}]?desliga)',
                'Set(TELIUM_ULT=${DB(sigame-ultimo/' . $eu . ')})',
                'GotoIf($["${TELIUM_ULT}" = ""]?sem_destino)',
                "Set(DB(sigame/{$eu})=\${TELIUM_ULT})",
                "Set(ODBC_TELIUM_SIGAME({$eu})=\${TELIUM_ULT})",
                'GoSub(sub-confirma,s,1(activated))',
            ], [
                'desliga' => [
                    'NoOp(${DB_DELETE(sigame/' . $eu . ')})',
                    "Set(ODBC_TELIUM_SIGAME({$eu})=)",
                    'GoSub(sub-confirma,s,1(de-activated))',
                ],
                'sem_destino' => [
                    'NoOp(Nunca houve destino de siga-me neste ramal)',
                    'GoSub(sub-confirma,s,1(pbx-invalid))',
                ],
            ]],

            // ---------------- lista negra e allowlist ----------------
            'listanegra_add' => [$comArg, [
                "NoOp(Bloquear {$arg})",
                "Set(DB(listanegra/{$arg})=1)",
                "Set(ODBC_TELIUM_LN_ADD({$arg})={$eu})",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'listanegra_del' => [$comArg, [
                "NoOp(Desbloquear {$arg})",
                'NoOp(${DB_DELETE(listanegra/' . $arg . ')})',
                "Set(ODBC_TELIUM_LN_DEL({$arg})=1)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'listanegra_ultimo' => [$codigo, [
                'NoOp(Bloquear o último chamador)',
                'Set(TELIUM_ULT=${DB(ultimochamador/' . $eu . ')})',
                'GotoIf($["${TELIUM_ULT}" = ""]?nada)',
                'Set(DB(listanegra/${TELIUM_ULT})=1)',
                "Set(ODBC_TELIUM_LN_ADD(\${TELIUM_ULT})={$eu})",
                'Answer()', 'Wait(1)', 'SayDigits(${TELIUM_ULT})',
                'Playback(activated)', 'Hangup()',
            ], ['nada' => [
                'NoOp(Nenhuma chamada registrada para este ramal)',
                'GoSub(sub-confirma,s,1(pbx-invalid))',
            ]]],
            'allowlist_add' => [$comArg, [
                "NoOp(Liberar {$arg})",
                "Set(DB(allowlist/{$arg})=1)",
                "Set(ODBC_TELIUM_AL_ADD({$arg})={$eu})",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'allowlist_del' => [$comArg, [
                "NoOp(Tirar {$arg} da allowlist)",
                'NoOp(${DB_DELETE(allowlist/' . $arg . ')})',
                "Set(ODBC_TELIUM_AL_DEL({$arg})=1)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'allowlist_ultimo' => [$codigo, [
                'NoOp(Liberar o último chamador)',
                'Set(TELIUM_ULT=${DB(ultimochamador/' . $eu . ')})',
                'GotoIf($["${TELIUM_ULT}" = ""]?nada)',
                'Set(DB(allowlist/${TELIUM_ULT})=1)',
                "Set(ODBC_TELIUM_AL_ADD(\${TELIUM_ULT})={$eu})",
                'Answer()', 'Wait(1)', 'SayDigits(${TELIUM_ULT})',
                'Playback(activated)', 'Hangup()',
            ], ['nada' => [
                'NoOp(Nenhuma chamada registrada para este ramal)',
                'GoSub(sub-confirma,s,1(pbx-invalid))',
            ]]],
            'allowlist_pausa' => [$codigo, [
                'NoOp(Pausar ou retomar a allowlist)',
                'GotoIf($[${DB_EXISTS(allowlist-pausada/geral)}]?retoma)',
                'Set(DB(allowlist-pausada/geral)=1)',
                'GoSub(sub-confirma,s,1(de-activated))',
            ], ['retoma' => [
                'NoOp(${DB_DELETE(allowlist-pausada/geral)})',
                'GoSub(sub-confirma,s,1(activated))',
            ]]],

            // ---------------- agenda ----------------
            'agenda_rapida' => [$comArg, [
                "NoOp(Discagem rápida {$arg})",
                'Set(TELIUM_NUM=${ODBC_TELIUM_CONTATO(' . $arg . ')})',
                'GotoIf($["${TELIUM_NUM}" = ""]?nada)',
                'Set(CALLERID(name)=${ODBC_TELIUM_CONTATO_NOME(' . $arg . ')})',
                'Goto(interno,${TELIUM_NUM},1)',
            ], ['nada' => [
                'NoOp(Nenhum contato com esse código de discagem rápida)',
                'GoSub(sub-confirma,s,1(pbx-invalid))',
            ]]],

            // ---------------- núcleo ----------------
            // Tom de discar é tom gerado, não gravação: Playback(dial)
            // pedia um arquivo que não existe em pacote de som nenhum, e
            // o Asterisk pulava a linha em silêncio. Playtones lê a
            // frequência de indications.conf — no Brasil, 425 Hz.
            'core_linha' => [$codigo, [
                'NoOp(Tom de discar interno)', 'Answer()', 'Wait(1)',
                'Playtones(dial)', 'WaitExten(15)', 'StopPlaytones()',
            ]],
            // Escuta: só o ramal de um usuário com a permissão de supervisor do
            // call center (família "escuta", gravada pelo "aplicar"). Aberto a
            // todos, qualquer ramal ouvia a conversa de qualquer outro. O "-"
            // fecha o nome: o ChanSpy casa pelo começo, e PJSIP/100 pegaria
            // também o 1001.
            'chanspy' => [$comArg, [
                "NoOp(Escutando o ramal {$arg})",
                'GotoIf($[!${DB_EXISTS(escuta/' . $eu . ')}]?negado)',
                'Answer()',
                "ChanSpy(PJSIP/{$arg}-,qs)", 'Hangup()',
            ], ['negado' => [
                'NoOp(O ramal ${CALLERID(num)} não tem permissão de escuta)',
                'Answer()', 'Wait(1)', 'Playback(access-denied)', 'Hangup()',
            ]]],
            'simular_entrada' => [$comArg, [
                "NoOp(Simulando chamada de entrada no DID {$arg})",
                'Set(CDR(direcao)=entrada)',
                "Goto(telium-entrada,{$arg},1)",
            ]],

            // ---------------- ditado ----------------
            // O ditado grava num diretório por ramal: sem isso, todo
            // mundo enxerga e sobrescreve a gravação de todo mundo, que
            // é como o Dictate se comporta com diretório comum.
            'ditado_gravar' => [$codigo, [
                'NoOp(Ditado)',
                'GotoIf($[${DB_EXISTS(ditado/' . $eu . ')}]?pode)',
                'Playback(cannot-complete-as-dialed)', 'Hangup()',
            ], ['pode' => [
                'Answer()', 'Wait(1)',
                "System(mkdir -p /var/spool/asterisk/dictate/{$eu})",
                "Dictate(/var/spool/asterisk/dictate/{$eu},{$eu})",
                'Hangup()',
            ]]],
            'ditado_email' => [$codigo, [
                'NoOp(Ditado para a caixa postal)',
                'GotoIf($[${DB_EXISTS(ditado/' . $eu . ')}]?pode)',
                'Playback(cannot-complete-as-dialed)', 'Hangup()',
            ], ['pode' => [
                'Answer()', 'Wait(1)',
                "VoiceMail({$eu}@telium,su)", 'Hangup()',
            ]]],

            // ---------------- fax ----------------
            'fax_receber' => [$codigo, [
                'NoOp(Receber fax)', 'Answer()', 'Wait(2)',
                'Set(FAXOPT(ecm)=yes)',
                'Set(TELIUM_FAX=/var/spool/asterisk/fax/${STRFTIME(${EPOCH},,%Y%m%d-%H%M%S)}-'
                    . '${CALLERID(num)}.tif)',
                'System(mkdir -p /var/spool/asterisk/fax)',
                'ReceiveFAX(${TELIUM_FAX})',
                'NoOp(Fax: ${FAXOPT(status)} — ${TELIUM_FAX})',
                'Hangup()',
            ]],

            // ---------------- despertador ----------------
            'despertar_marcar' => [$codigo, [
                'NoOp(Marcar despertador)', 'Answer()', 'Wait(1)',
                'Playback(vm-enter-num-to-call)',
                'Read(TELIUM_HORA,beep,4,,3,8)',
                'GotoIf($[${LEN(${TELIUM_HORA})} != 4]?erro)',
                "Set(DB(despertar/{$eu})=\${TELIUM_HORA})",
                'SayDigits(${TELIUM_HORA})',
                'Playback(activated)', 'Hangup()',
            ], ['erro' => [
                'NoOp(Hora inválida)', 'Playback(pbx-invalid)', 'Hangup()',
            ]]],
            'despertar_cancelar' => [$codigo, [
                'NoOp(Cancelar despertador)',
                'NoOp(${DB_DELETE(despertar/' . $eu . ')})',
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],

            // ---------------- chamada perdida ----------------
            'perdidas_on' => [$codigo, [
                'NoOp(Ativar aviso de chamada perdida)',
                "Set(DB(perdidas/{$eu})=1)",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'perdidas_off' => [$codigo, [
                'NoOp(Desativar aviso de chamada perdida)',
                'NoOp(${DB_DELETE(perdidas/' . $eu . ')})',
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],
            'perdidas_alterna' => [$codigo, [
                'NoOp(Alternar aviso de chamada perdida)',
                'GotoIf($[${DB_EXISTS(perdidas/' . $eu . ')}]?desliga)',
                "Set(DB(perdidas/{$eu})=1)",
                'GoSub(sub-confirma,s,1(activated))',
            ], ['desliga' => [
                'NoOp(${DB_DELETE(perdidas/' . $eu . ')})',
                'GoSub(sub-confirma,s,1(de-activated))',
            ]]],

            // ---------------- interfonia ----------------
            'interfonia_prefixo' => [$comArg, [
                "NoOp(Interfonia para {$arg})",
                'GotoIf($[${DB_EXISTS(interfonia-nao/' . $arg . ')}]?recusado)',
                'Set(CALLERID(name)=Interfonia ${CALLERID(num)})',
                // b: o aparelho atende sozinho; sem isso a interfonia só tocava.
                "Page(PJSIP/{$arg},dqb(sub-autoatende^s^1),30)",
                'Hangup()',
            ], ['recusado' => [
                "NoOp(O ramal {$arg} recusa interfonia)",
                "Goto(interno,{$arg},1)",
            ]]],
            'interfonia_permitir' => [$codigo, [
                'NoOp(Aceitar interfonia)',
                'NoOp(${DB_DELETE(interfonia-nao/' . $eu . ')})',
                "Set(ODBC_TELIUM_INTERFONIA({$eu})=permitir)",
                'GoSub(sub-confirma,s,1(activated))',
            ]],
            'interfonia_negar' => [$codigo, [
                'NoOp(Recusar interfonia)',
                "Set(DB(interfonia-nao/{$eu})=1)",
                "Set(ODBC_TELIUM_INTERFONIA({$eu})=negar)",
                'GoSub(sub-confirma,s,1(de-activated))',
            ]],

            // ---------------- filas ----------------
            'fila_login' => [$comArg, array_values(array_filter([
                "NoOp(Entrar ou sair da fila {$arg})", 'Answer()', 'Wait(1)',
                $this->recusaCallCenter($arg),
                'Set(TELIUM_MEMBRO=PJSIP/' . $eu . ')',
                // "status" não é opção do QUEUE_MEMBER (o Asterisk dá erro e o
                // teste sempre caía em "sair"), e "penalty" devolve 0 também
                // para quem não é membro. A lista de membros não mente.
                'Set(TELIUM_MEMBROS=${QUEUE_MEMBER_LIST(' . $arg . ')})',
                'GotoIf($[${REGEX("(^|,)${TELIUM_MEMBRO}(,|$)" ${TELIUM_MEMBROS})} = 0]?entra)',
                "RemoveQueueMember({$arg},\${TELIUM_MEMBRO})",
                'Playback(agent-loggedoff)', 'Hangup()',
            ])), ['entra' => [
                "AddQueueMember({$arg},\${TELIUM_MEMBRO})",
                'Playback(agent-loginok)', 'Hangup()',
            ]] + $this->avisoCallCenter()],
            'fila_pausa' => [$comArg, array_values(array_filter([
                "NoOp(Pausar ou despausar na fila {$arg})", 'Answer()', 'Wait(1)',
                $this->recusaCallCenter($arg),
                'Set(TELIUM_MEMBRO=PJSIP/' . $eu . ')',
                'GotoIf($["${QUEUE_MEMBER(' . $arg . ',paused,${TELIUM_MEMBRO})}" = "1"]?volta)',
                "PauseQueueMember({$arg},\${TELIUM_MEMBRO})",
                'Playback(activated)', 'Hangup()',
            ])), ['volta' => [
                "UnpauseQueueMember({$arg},\${TELIUM_MEMBRO})",
                'Playback(de-activated)', 'Hangup()',
            ]] + $this->avisoCallCenter()],
            // ---------------- call center ----------------
            // A decisão fica com o serviço de tempo real, pela mesma
            // classe do console: telium-cc-telefone só pergunta a ele.
            'cc_login' => [$codigo, [
                'NoOp(Call center: entrar)', 'GoSub(telium-cc-telefone,login,1)', 'Hangup()',
            ]],
            'cc_logout' => [$codigo, [
                'NoOp(Call center: sair)', 'GoSub(telium-cc-telefone,logout,1)', 'Hangup()',
            ]],
            // Pausar não está aqui: cada motivo de pausa tem o seu código (pausas()).
            'cc_volta' => [$codigo, [
                'NoOp(Call center: voltar da pausa)', 'GoSub(telium-cc-telefone,volta,1)', 'Hangup()',
            ]],

            'fila_contagem' => [$comArg, [
                "NoOp(Chamadas esperando na fila {$arg})", 'Answer()', 'Wait(1)',
                'SayNumber(${QUEUE_WAITING_COUNT(' . $arg . ')})',
                'Playback(queue-callswaiting)', 'Hangup()',
            ]],

            // ---------------- conferência ----------------
            'conf_estado' => [$comArg, [
                "NoOp(Estado da conferência {$arg})", 'Answer()', 'Wait(1)',
                'SayNumber(${CONFBRIDGE_INFO(parties,' . $arg . ')})',
                'Playback(conf-onlyperson)', 'Hangup()',
            ]],

            // ---------------- condições horárias ----------------
            // condicao_alterna: cada condição tem o seu código, com a tecla
            // BLF, gerado junto com as condições (GeradorDialplan) — aqui ele
            // é só o prefixo.

            default => null,
        };
    }

    // ---- fábricas dos quatro sabores de desvio -----------------------
    private function desvioLiga(string $fam, string $exten, string $arg, string $eu, string $rot): array
    {
        return [$exten, [
            "NoOp(Desvio {$rot} para {$arg})",
            "Set(DB({$fam}/{$eu})={$arg})",
            "Set(DB({$fam}-ultimo/{$eu})={$arg})",
            'GoSub(sub-confirma,s,1(call-fwd-unconditional))',
        ]];
    }

    private function desvioDesliga(string $fam, string $codigo, string $eu, string $rot): array
    {
        return [$codigo, [
            "NoOp(Cancelar desvio {$rot})",
            'NoOp(${DB_DELETE(' . $fam . '/' . $eu . ')})',
            'GoSub(sub-confirma,s,1(de-activated))',
        ]];
    }

    private function desvioPergunta(string $fam, string $codigo, string $eu, string $rot): array
    {
        return [$codigo, [
            "NoOp(Desvio {$rot}: perguntando o destino)",
            'Answer()', 'Wait(1)',
            // "then" não existe em pacote de som nenhum, e "press-pound"
            // só no extra: a frase tocava pela metade e ninguém percebia,
            // porque Playback pula o arquivo que falta sem reclamar.
            // vm-extension e vm-then-pound vêm no pacote básico e dizem a
            // mesma coisa: "ramal ... e então tecle jogo da velha".
            'Playback(vm-extension&vm-then-pound)',
            'Read(TELIUM_DEST,beep,,,3,10)',
            'GotoIf($["${TELIUM_DEST}" = ""]?erro)',
            "Set(DB({$fam}/{$eu})=\${TELIUM_DEST})",
            "Set(DB({$fam}-ultimo/{$eu})=\${TELIUM_DEST})",
            'SayDigits(${TELIUM_DEST})',
            'Playback(activated)', 'Hangup()',
        ], ['erro' => [
            'NoOp(Destino não informado)', 'Playback(pbx-invalid)', 'Hangup()',
        ]]];
    }

    private function desvioAlterna(string $fam, string $codigo, string $eu, string $rot): array
    {
        return [$codigo, [
            "NoOp(Alternar desvio {$rot})",
            'GotoIf($[${DB_EXISTS(' . $fam . '/' . $eu . ')}]?desliga)',
            'Set(TELIUM_ULT=${DB(' . $fam . '-ultimo/' . $eu . ')})',
            'GotoIf($["${TELIUM_ULT}" = ""]?sem_destino)',
            "Set(DB({$fam}/{$eu})=\${TELIUM_ULT})",
            'GoSub(sub-confirma,s,1(activated))',
        ], [
            'desliga' => [
                'NoOp(${DB_DELETE(' . $fam . '/' . $eu . ')})',
                'GoSub(sub-confirma,s,1(de-activated))',
            ],
            'sem_destino' => [
                'NoOp(Nunca houve destino de desvio neste ramal)',
                'GoSub(sub-confirma,s,1(pbx-invalid))',
            ],
        ]];
    }

    private function cabecalho(string $titulo): Bloco
    {
        return (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario($titulo)
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->comentario('Personalizações vão em extensions_custom.conf')
            ->branco();
    }
}
