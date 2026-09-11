<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/** Gera os contextos telium-* do dialplan. */
final class GeradorDialplan
{
    private Destino $destino;
    /** @var array<string,array<string,mixed>> */
    private array $ramais;
    /** @var array<int,array<string,mixed>> anúncios ativos, indexados por id */
    private array $anuncios;

    public function __construct()
    {
        $lista = Bd::todos('SELECT * FROM ramais WHERE ativo = 1 ORDER BY numero');
        $this->ramais = array_column($lista, null, 'numero');

        $personalizados = array_column(
            Bd::todos('SELECT * FROM destinos_personalizados WHERE ativo = 1'),
            null,
            'id'
        );

        // Nenhum módulo guarda mais nome de arquivo: todos apontam para um
        // anúncio, e é aqui que o id vira o arquivo que o Asterisk toca.
        $this->anuncios = array_column(
            Bd::todos('SELECT a.*, s.arquivo FROM anuncios a JOIN audios s ON s.id = a.audio_id'),
            null,
            'id'
        );

        $this->destino = new Destino($this->ramais, $personalizados);
    }

    /** @return array<string,string> */
    public function gerar(): array
    {
        return [
            'extensions.listanegra.conf' => $this->listaNegra(),
            'extensions.allowlist.conf'  => $this->listaPermitida(),
            'extensions.ramais.conf'  => $this->ramais(),
            'extensions.grupos.conf'  => $this->grupos(),
            'extensions.filas.conf'   => $this->filas(),
            'extensions.pesquisa.conf' => $this->pesquisas(),
            'extensions.confirmacao.conf' => $this->confirmacoesDeFila(),
            'extensions.conferencias.conf' => $this->conferencias(),
            'extensions.anuncios.conf' => $this->anuncios(),
            'extensions.ura.conf'     => $this->uras(),
            'extensions.saida.conf'   => $this->rotasSaida(),
            'extensions.entrada.conf' => $this->rotasEntrada(),
        ];
    }

    /**
     * O arquivo que um anúncio toca, ou '' se ele não existe mais.
     *
     * Quando um módulo só precisa do prompt — a saudação da URA, o
     * sussurro da fila — é isto que ele usa; o destino do anúncio só
     * vale quando o anúncio é o destino da chamada.
     */
    private function audioDoAnuncio(mixed $id): string
    {
        $a = $this->anuncios[(int) $id] ?? null;

        return $a === null || (int) $a['ativo'] !== 1 ? '' : (string) $a['arquivo'];
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

    // ---------------------------------------------------------------
    /**
     * Códigos de recurso (*8, *43, *97…). Os números vêm do banco para
     * poderem ser trocados pelo console sem mexer em arquivo.
     */
    /**
     * Lista negra. Em vez de varrer uma tabela em tempo de chamada, cada
     * número vira uma extensão neste contexto e o dialplan pergunta ao
     * próprio Asterisk se ela existe — o que também faz padrões valerem.
     */
    private function listaNegra(): string
    {
        $b = $this->cabecalho('Contexto: lista negra de entrantes')
                  ->contexto('telium-listanegra');

        $bloqueados = Bd::todos(
            'SELECT ln.*, s.arquivo AS audio
               FROM lista_negra ln
          LEFT JOIN anuncios an ON an.id = ln.anuncio_id AND an.ativo = 1
          LEFT JOIN audios s ON s.id = an.audio_id
              WHERE ln.ativo = 1
           ORDER BY ln.numero'
        );

        if ($bloqueados === []) {
            return $b->comentario('nenhum número bloqueado')->texto();
        }

        foreach ($bloqueados as $n) {
            $numero = (string) $n['numero'];

            $b->branco()
              ->comentario(($n['descricao'] ?: 'sem descrição') . " — {$n['tratamento']}")
              ->exten($numero, "NoOp(Lista negra: {$numero})")
              ->same('Set(CDR(userfield)=lista-negra)');

            $b->apps(match ($n['tratamento']) {
                'ocupado'  => ['Busy(5)', 'Hangup()'],
                'silencio' => ['Answer()', 'Wait(600)', 'Hangup()'],
                'anuncio'  => $n['audio']
                    ? ['Answer()', 'Wait(1)', "Playback({$n['audio']})", 'Hangup()']
                    : ['Answer()', 'Wait(1)', 'Playback(ss-noservice)', 'Hangup()'],
                default    => ['Hangup(21)'],
            });
        }

        return $b->texto();
    }

    /**
     * Allowlist: números que a lista negra nunca barra, mesmo casando
     * com algum padrão dela.
     */
    private function listaPermitida(): string
    {
        $b = $this->cabecalho('Contexto: exceções da lista negra')
                  ->contexto('telium-allowlist');

        $permitidos = Bd::todos('SELECT * FROM lista_permitida WHERE ativo = 1 ORDER BY numero');

        if ($permitidos === []) {
            return $b->comentario('nenhuma exceção cadastrada')->texto();
        }

        foreach ($permitidos as $n) {
            $b->comentario((string) ($n['descricao'] ?: 'sem descrição'))
              ->exten((string) $n['numero'], 'NoOp(Allowlist)')
              ->same('Return()');
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function ramais(): string
    {
        $b = $this->cabecalho('Contexto: ramais internos')->contexto('telium-ramais');

        foreach ($this->ramais as $r) {
            $numero = $r['numero'];
            $b->branco()
              ->comentario("{$numero} — {$r['nome']}")
              ->exten($numero, "NoOp(Ramal {$numero} — {$r['nome']})")
              ->same('Set(CDR(direcao)=interna)');

            if (in_array($r['gravar'], ['ambas', 'entrada'], true)) {
                $b->same('GoSub(sub-gravar,s,1(interna))');
            }
            if ((int) $r['dnd'] === 1) {
                $b->same('NoOp(Ramal em não perturbe)')
                  ->same((int) $r['voicemail'] === 1
                      ? "VoiceMail({$numero}@telium,b)"
                      : 'Busy(5)')
                  ->same('Hangup()');
                continue;
            }
            if ($r['siga_me']) {
                $b->same(sprintf(
                    'Dial(PJSIP/%s&PJSIP/%s@%s,%d,tT)',
                    $numero,
                    $r['siga_me'],
                    'SIP-Vivo-Principal',
                    (int) $r['tempo_toque']
                ));
                $b->same('Hangup()');
                continue;
            }

            $b->apps($this->destino->linhas('ramal', $numero));
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function grupos(): string
    {
        $b = $this->cabecalho('Contexto: grupos de toque')->contexto('telium-grupos');

        foreach (Bd::todos('SELECT * FROM grupos_toque WHERE ativo = 1 ORDER BY numero') as $g) {
            $canais = implode('&', array_map(
                static fn (string $r): string => 'PJSIP/' . trim($r),
                array_filter(explode('-', (string) $g['ramais']))
            ));

            $b->branco()
              ->comentario("{$g['numero']} — {$g['nome']}")
              ->exten($g['numero'], "NoOp(Grupo de toque {$g['nome']})")
              ->same('Set(CDR(direcao)=interna)')
              ->same(sprintf('Dial(%s,%d,tT)', $canais, (int) $g['tempo_toque']))
              ->apps($this->destino->linhas($g['destino_falha_tipo'], $g['destino_falha_valor']));
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function filas(): string
    {
        $b = $this->cabecalho('Contexto: filas de atendimento')->contexto('telium-filas');

        // Uma pesquisa desligada não pode virar destino: o Goto cairia
        // numa extensão que o gerador não escreveu.
        $ativas = array_column(Bd::todos('SELECT id FROM pesquisas WHERE ativo = 1'), 'id');

        foreach (Bd::todos('SELECT * FROM filas WHERE ativo = 1 ORDER BY numero') as $f) {
            $numero = (string) $f['numero'];
            $pesquisa = (int) ($f['pesquisa_id'] ?? 0);
            if ($pesquisa > 0 && !in_array($pesquisa, array_map('intval', $ativas), true)) {
                $pesquisa = 0;
            }

            $b->branco()
              ->comentario("{$numero} — {$f['nome']} ({$f['estrategia']})"
                  . ((int) $f['callcenter'] === 1 ? ' — call center' : ''))
              ->exten($numero, "NoOp(Fila {$f['nome']})")
              ->same("Set(CDR(fila)={$numero})")
              ->same("Set(__TELIUM_FILA={$numero})")
              ->same('Answer()');

            if ((int) $f['gravar'] === 1) {
                $b->same('GoSub(sub-gravar,s,1(entrada))');
            }

            // Anúncio ao cliente antes de entrar na espera. Fica fora do
            // announce da fila de propósito: aquele é o sussurro do agente.
            $entrada = $this->audioDoAnuncio($f['anuncio_entrada_id'] ?? 0);
            if ($entrada !== '') {
                $b->same("Playback({$entrada})");
            }

            // 'c' devolve o cliente ao dialplan quando o atendente desliga;
            // é o que leva ele para a pesquisa. Sem pesquisa não usamos a
            // opção, e a chamada termina junto com o agente, como sempre.
            $opcoes = 'tT' . ($pesquisa > 0 ? 'c' : '');

            $b->same(sprintf(
                'Queue(%s,%s,,,%d)',
                $numero,
                $opcoes,
                (int) $f['max_espera']
            ));

            $b->same('NoOp(Saída da fila ' . $numero . ': ${QUEUESTATUS})');

            if ($pesquisa > 0) {
                // QUEUESTATUS vazio = a chamada foi atendida e o agente
                // desligou; qualquer valor ali é motivo de não-atendimento.
                $b->same('GotoIf($["${QUEUESTATUS}" = ""]?pesquisa-' . $numero . ')');
            }

            $temVazia = ($f['destino_vazia_tipo'] ?? '') !== '';
            $temCheia = ($f['destino_cheia_tipo'] ?? '') !== '';

            if ($temVazia) {
                $b->same('GotoIf($["${QUEUESTATUS}" = "JOINEMPTY" | "${QUEUESTATUS}" = "LEAVEEMPTY"'
                    . ' | "${QUEUESTATUS}" = "JOINUNAVAIL" | "${QUEUESTATUS}" = "LEAVEUNAVAIL"]'
                    . '?vazia-' . $numero . ')');
            }
            if ($temCheia) {
                $b->same('GotoIf($["${QUEUESTATUS}" = "FULL"]?cheia-' . $numero . ')');
            }

            // Saída padrão: tempo esgotado ou motivo sem destino próprio.
            $b->apps($this->destino->linhas($f['destino_estouro_tipo'], $f['destino_estouro_valor']));

            if ($temVazia) {
                $linhas = $this->destino->linhas($f['destino_vazia_tipo'], $f['destino_vazia_valor']);
                $b->same(array_shift($linhas), 'vazia-' . $numero)->apps($linhas);
            }
            if ($temCheia) {
                $linhas = $this->destino->linhas($f['destino_cheia_tipo'], $f['destino_cheia_valor']);
                $b->same(array_shift($linhas), 'cheia-' . $numero)->apps($linhas);
            }
            if ($pesquisa > 0) {
                $b->same("Goto(telium-pesquisa,{$pesquisa},1)", 'pesquisa-' . $numero);
            }
        }

        return $b->texto();
    }

    /**
     * Anúncios.
     *
     * O anúncio é uma gravação mais o que fazer com ela: deixar pular,
     * repetir numa tecla, voltar para a URA de onde a chamada veio e
     * para onde ir depois. É o que os outros módulos apontam quando
     * precisam tocar alguma coisa — nenhum guarda nome de arquivo.
     */
    private function anuncios(): string
    {
        $b = $this->cabecalho('Contextos: anúncios');

        $ativos = array_filter($this->anuncios, static fn (array $a): bool => (int) $a['ativo'] === 1);

        // Cada anúncio tem contexto próprio, como as URAs. Num contexto
        // só, a tecla que repete um anúncio colidiria com o número de
        // outro — o anúncio 2 repetindo no 1 roubaria o anúncio 1.
        $b->contexto('telium-anuncios');
        foreach ($ativos as $a) {
            $b->exten((string) $a['id'], "Goto(telium-anuncio-{$a['id']},s,1)");
        }
        if ($ativos === []) {
            $b->comentario('nenhum anúncio ativo');
        }
        $b->branco();

        foreach ($ativos as $a) {
            $id = (int) $a['id'];
            $pular = (int) $a['permitir_pular'] === 1;
            $repete = trim((string) ($a['repetir_tecla'] ?? ''));

            // Com tecla para pular ou repetir, o áudio tem de ser tocado
            // por Background, que escuta o teclado; Playback não escuta.
            $tocar = ($pular || $repete !== '')
                ? "Background({$a['arquivo']})"
                : "Playback({$a['arquivo']})";

            $b->comentario(str_repeat('-', 62))
              ->comentario("Anúncio {$id} — {$a['nome']}")
              ->comentario(str_repeat('-', 62))
              ->contexto("telium-anuncio-{$id}")
              ->exten('s', "NoOp(Anúncio: {$a['nome']})");

            if ((int) $a['nao_responder'] === 1) {
                // Sem atender, a operadora não tarifa a chamada.
                $b->same('NoOp(Tocando sem atender o canal)');
            } else {
                $b->same('Answer()')->same('Wait(1)');
            }

            $b->same($tocar, 'toca');

            if ($repete !== '' || $pular) {
                $b->same('WaitExten(1)');
            }

            $saida = $this->saidaDoAnuncio($a);
            $b->same(array_shift($saida), 'saida')->apps($saida);

            if ($repete !== '') {
                $b->branco()
                  ->comentario("tecla {$repete} ouve de novo")
                  ->exten($repete, 'Goto(s,toca)');
            }
            if ($pular || $repete !== '') {
                $b->comentario('qualquer outra tecla, ou o tempo acabar, segue adiante')
                  ->exten('i', 'Goto(s,saida)')
                  ->exten('t', 'Goto(s,saida)');
            }

            $b->branco();
        }

        return $b->texto();
    }

    /**
     * Para onde a chamada vai quando o anúncio termina.
     *
     * @return string[]
     */
    private function saidaDoAnuncio(array $a): array
    {
        $linhas = ['NoOp(Fim do anúncio)'];

        if ((int) $a['retornar_ura'] === 1) {
            // A URA marca de onde a chamada saiu; sem essa marca, não há
            // para onde voltar e vale o destino configurado.
            $linhas[] = 'ExecIf($["${TELIUM_URA_ORIGEM}" != ""]'
                      . '?Goto(telium-ura-${TELIUM_URA_ORIGEM},s,1))';
            $linhas[] = 'NoOp(A chamada não veio de uma URA; vale o destino configurado)';
        }

        return [...$linhas, ...$this->destino->linhas($a['destino_tipo'], $a['destino_valor'])];
    }

    /**
     * Salas de conferência.
     *
     * O PIN é conferido aqui, e não no ConfBridge, porque assim o mesmo
     * número serve para participante e administrador: quem digita o PIN
     * de admin entra com o perfil que tranca a sala e expulsa gente.
     */
    private function conferencias(): string
    {
        $b = $this->cabecalho('Contexto: salas de conferência')->contexto('telium-conferencias');

        $salas = Bd::todos('SELECT * FROM conferencias WHERE ativo = 1 ORDER BY numero');

        if ($salas === []) {
            return $b->comentario('nenhuma sala ativa')->texto();
        }

        foreach ($salas as $s) {
            $n = (string) $s['numero'];
            $pin = trim((string) ($s['pin'] ?? ''));
            $pinAdmin = trim((string) ($s['pin_admin'] ?? ''));
            $temPin = $pin !== '' || $pinAdmin !== '';

            $b->branco()
              ->comentario("{$n} — {$s['nome']}")
              ->exten($n, "NoOp(Conferência {$s['nome']})")
              ->same('Answer()')
              ->same('Wait(1)')
              ->same("Set(CDR(userfield)=conferencia-{$n})")
              ->same("Set(TELIUM_PERFIL=sala-{$n}-participante)")
              ->same("Set(TELIUM_MENU=telium-menu)");

            if ($temPin) {
                // Lê exatamente o tamanho do maior PIN: assim o participante
                // não precisa terminar com # para o Asterisk seguir.
                $digitos = max(strlen($pin), strlen($pinAdmin));

                $b->same('Set(TELIUM_TENTATIVA=0)')
                  ->same('Set(TELIUM_TENTATIVA=$[${TELIUM_TENTATIVA} + 1])', 'pin')
                  ->same('GotoIf($[${TELIUM_TENTATIVA} > 3]?semsorte)')
                  ->same("Read(TELIUM_PIN,conf-getpin,{$digitos},,1,8)");

                if ($pinAdmin !== '') {
                    $b->same('GotoIf($["${TELIUM_PIN}" = "' . $pinAdmin . '"]?admin)');
                }
                if ($pin !== '') {
                    $b->same('GotoIf($["${TELIUM_PIN}" = "' . $pin . '"]?entra)');
                } else {
                    // Só existe PIN de admin: quem não digitou entra como participante.
                    $b->same('GotoIf($["${TELIUM_PIN}" = ""]?entra)');
                }

                $b->same('Playback(conf-invalidpin)')
                  ->same('Goto(pin)')
                  ->same("Set(TELIUM_PERFIL=sala-{$n}-admin)", 'admin')
                  ->same('Set(TELIUM_MENU=telium-menu-admin)')
                  ->same('NoOp(PIN aceito)', 'entra');
            }

            $entrada = $this->audioDoAnuncio($s['anuncio_entrada_id'] ?? 0);
            if ($entrada !== '') {
                $b->same("Playback({$entrada})");
            }
            if ((int) $s['gravar'] === 1) {
                // Mesmo formato e mesma árvore das gravações de chamada,
                // para o módulo de gravações achar o arquivo.
                $b->same('Set(TELIUM_CONFARQ=${STRFTIME(${EPOCH},,%Y/%m/%d)}/'
                       . "conferencia-{$n}-\${STRFTIME(\${EPOCH},,%H%M%S)}-\${UNIQUEID}.wav)")
                  ->same('System(mkdir -p ${TELIUM_GRAVACOES}/${STRFTIME(${EPOCH},,%Y/%m/%d)})')
                  ->same('Set(CONFBRIDGE(bridge,record_conference)=yes)')
                  ->same('Set(CONFBRIDGE(bridge,record_file)=${TELIUM_GRAVACOES}/${TELIUM_CONFARQ})')
                  ->same('Set(ODBC_TELIUM_GRAVACAO(${UNIQUEID},${TELIUM_CONFARQ},${CALLERID(num)},'
                       . "{$n},conferencia)=\${CALLERID(name)})");
            }

            $b->same(sprintf(
                'ConfBridge(%s,sala-%s,${TELIUM_PERFIL},${TELIUM_MENU})',
                $n,
                $n
            ))
              ->same('Hangup()');

            if ($temPin) {
                $b->same('NoOp(PIN errado três vezes)', 'semsorte')
                  ->same('Playback(conf-invalidpin)')
                  ->same('Hangup()');
            }
        }

        return $b->texto();
    }

    /**
     * Um contexto de confirmação por fila. O membro da fila é um canal
     * Local que entra aqui, e o U() do Dial roda a sub-rotina que pede o
     * dígito no canal de quem vai atender.
     */
    private function confirmacoesDeFila(): string
    {
        $b = $this->cabecalho('Contextos: confirmação de atendimento nas filas');

        $filas = Bd::todos(
            'SELECT numero, nome, anuncio_agente_id FROM filas
              WHERE ativo = 1 AND confirmar_atendimento = 1 ORDER BY numero'
        );

        if ($filas === []) {
            return $b->comentario('nenhuma fila com confirmação de atendimento')->texto();
        }

        foreach ($filas as $f) {
            $audio = $this->audioDoAnuncio($f['anuncio_agente_id'] ?? 0);

            $b->branco()
              ->comentario("Fila {$f['numero']} — {$f['nome']}")
              ->contexto("telium-confirma-{$f['numero']}")
              ->exten('_X.', "NoOp(Confirmação da fila {$f['numero']} para o ramal \${EXTEN})")
              ->same("Set(__TELIUM_AUDIO={$audio})")
              ->same('Dial(PJSIP/${EXTEN},,U(sub-confirmar-atendimento))')
              ->same('Hangup()');
        }

        return $b->texto();
    }

    /**
     * Pesquisa de satisfação: o cliente chega aqui quando o atendente
     * desliga, pela opção 'c' do Queue. A nota vai para o banco pelo
     * func_odbc; desligar sem responder também é registrado, porque
     * "não respondeu" é informação.
     */
    private function pesquisas(): string
    {
        $b = $this->cabecalho('Contexto: pesquisa de satisfação')->contexto('telium-pesquisa');

        $pesquisas = Bd::todos('SELECT * FROM pesquisas WHERE ativo = 1 ORDER BY id');

        if ($pesquisas === []) {
            return $b->comentario('nenhuma pesquisa ativa')->texto();
        }

        foreach ($pesquisas as $p) {
            $id = (int) $p['id'];
            $min = (int) $p['nota_min'];
            $max = (int) $p['nota_max'];
            $prompt = $this->audioDoAnuncio($p['anuncio_pergunta_id'] ?? 0) ?: 'beep';

            $b->branco()
              ->comentario("{$id} — {$p['nome']} (nota de {$min} a {$max})")
              ->exten((string) $id, "NoOp(Pesquisa: {$p['nome']})")
              ->same('Set(TELIUM_NOTA=)')
              ->same(sprintf(
                  'Read(TELIUM_NOTA,%s,1,,%d,%d)',
                  $prompt,
                  max(1, (int) $p['tentativas']),
                  max(3, (int) $p['segundos'])
              ))
              ->same('GotoIf($["${TELIUM_NOTA}" = ""]?sem-' . $id . ')')
              ->same('GotoIf($[${TELIUM_NOTA} < ' . $min . ' | ${TELIUM_NOTA} > ' . $max
                  . ']?invalida-' . $id . ')')
              ->same(sprintf(
                  'Set(ODBC_TELIUM_PESQUISA(%d,${TELIUM_FILA},${MEMBERINTERFACE},'
                  . '${CALLERID(num)},${UNIQUEID})=${TELIUM_NOTA})',
                  $id
              ))
              ->same('NoOp(Nota ${TELIUM_NOTA} registrada)');

            $obrigado = $this->audioDoAnuncio($p['anuncio_obrigado_id'] ?? 0);
            $b->same('Playback(' . ($obrigado ?: 'auth-thankyou') . ')');

            $b->same('Hangup()')
              // Uma tecla fora da faixa vira nova tentativa, não descarte.
              ->same('Playback(pbx-invalid)', 'invalida-' . $id)
              ->same("Goto(telium-pesquisa,{$id},1)")
              ->same(sprintf(
                  'Set(ODBC_TELIUM_PESQUISA(%d,${TELIUM_FILA},${MEMBERINTERFACE},'
                  . '${CALLERID(num)},${UNIQUEID})=)',
                  $id
              ), 'sem-' . $id)
              ->same('NoOp(Cliente não respondeu)')
              ->same('Hangup()');
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function uras(): string
    {
        $b = $this->cabecalho('Contextos: URAs');
        $uras = Bd::todos('SELECT * FROM ura WHERE ativo = 1 ORDER BY id');

        // Contexto de entrada: telium-ura → cada URA
        $b->contexto('telium-ura');
        foreach ($uras as $u) {
            $b->exten("ura-{$u['id']}", "Goto(telium-ura-{$u['id']},s,1)");
        }
        $b->branco();

        foreach ($uras as $u) {
            $tentativas = max(1, (int) $u['tentativas']);
            $saudacao = $this->audioDoAnuncio($u['anuncio_id'] ?? 0);
            $b->comentario(str_repeat('-', 62))
              ->comentario("URA {$u['id']} — {$u['nome']}")
              ->comentario(str_repeat('-', 62))
              ->contexto("telium-ura-{$u['id']}")
              ->exten('s', "NoOp(URA {$u['nome']})")
              ->same('Answer()')
              ->same('Wait(1)')
              ->same('Set(TENTATIVA=0)')
              ->same('Set(INVALIDAS=0)')
              ->same('Set(TENTATIVA=$[${TENTATIVA} + 1])', 'menu')
              ->same("GotoIf(\$[\${TENTATIVA} > {$tentativas}]?falha)")
              ->same($saudacao !== ''
                  ? "Background({$saudacao})"
                  : 'NoOp(URA sem anúncio de saudação escolhido)')
              ->same("WaitExten({$u['timeout_digito']})")
              ->same('NoOp(Sem resposta na URA)', 'falha')
              ->apps($this->destino->linhas($u['destino_timeout_tipo'], $u['destino_timeout_valor']));

            $opcoes = Bd::todos(
                'SELECT * FROM ura_opcoes WHERE ura_id = ? ORDER BY ordem, tecla',
                [$u['id']]
            );

            foreach ($opcoes as $o) {
                $b->branco()
                  ->comentario("tecla {$o['tecla']} → {$o['rotulo']} ("
                      . $this->destino->descricao($o['destino_tipo'], $o['destino_valor']) . ')')
                  ->exten($o['tecla'], "NoOp({$o['rotulo']})")
                  // Marca de onde a chamada saiu: é o que deixa um anúncio
                  // com "retornar para a URA" saber para onde voltar.
                  ->same("Set(__TELIUM_URA_ORIGEM={$u['id']})");

                // "repetir menu" aponta para a própria URA: volta ao rótulo
                if ($o['destino_tipo'] === 'ura' && (int) $o['destino_valor'] === (int) $u['id']) {
                    $b->same('Goto(s,menu)');
                    continue;
                }
                $b->apps($this->destino->linhas($o['destino_tipo'], $o['destino_valor']));
            }

            $b->branco();

            if ((int) $u['discagem_direta'] === 1) {
                $b->comentario('discagem direta de ramal')
                  ->exten('_XXXX', 'Goto(telium-ramais,${EXTEN},1)')
                  ->exten('_XXX',  'Goto(telium-ramais,${EXTEN},1)');
            }

            // Tecla que não existe no menu: avisa e repete. Depois de
            // insistir o mesmo número de vezes, vai para o destino de
            // inválido — ou para o de tempo esgotado, se não houver um.
            $invalido = ($u['destino_invalido_tipo'] ?? '') !== ''
                ? $this->destino->linhas($u['destino_invalido_tipo'], $u['destino_invalido_valor'])
                : $this->destino->linhas($u['destino_timeout_tipo'], $u['destino_timeout_valor']);

            $b->comentario('opção inválida e tempo esgotado')
              ->exten('i', 'Set(INVALIDAS=$[${INVALIDAS} + 1])')
              ->same("GotoIf(\$[\${INVALIDAS} >= {$tentativas}]?desiste)")
              ->same('Playback(invalid)')
              ->same('Goto(s,menu)')
              ->same('NoOp(Cliente insistiu em opção inválida)', 'desiste')
              ->apps($invalido)
              ->exten('t', 'Goto(s,menu)')
              ->branco();
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function rotasSaida(): string
    {
        $b = $this->cabecalho('Contexto: rotas de saída (ordem de precedência)')
                  ->contexto('telium-saida');

        $rotas = Bd::todos(
            'SELECT r.*, t.nome AS tronco_nome, tf.nome AS tronco_falha_nome
               FROM rotas_saida r
               JOIN troncos t  ON t.id = r.tronco_id
          LEFT JOIN troncos tf ON tf.id = r.tronco_falha_id
              WHERE r.ativo = 1
           ORDER BY r.ordem, r.id'
        );

        foreach ($rotas as $r) {
            $tronco = $this->identificador((string) $r['tronco_nome']);
            $numero = $r['prefixo_remover']
                ? '${EXTEN:' . strlen((string) $r['prefixo_remover']) . '}'
                : '${EXTEN}';
            if ($r['prefixo_adicionar']) {
                $numero = $r['prefixo_adicionar'] . $numero;
            }

            $b->branco()
              ->comentario("Ordem {$r['ordem']} — {$r['nome']} ({$r['classe']}) via {$r['tronco_nome']}")
              ->exten($r['padrao'], "NoOp(Rota de saída: {$r['nome']})")
              ->same('Set(CDR(direcao)=saida)')
              ->same("Set(CDR(tronco)={$r['tronco_nome']})")
              ->same("GoSub(sub-permissao,s,1({$r['classe']}))");

            if ($r['pin_set_id']) {
                $b->same('Authenticate(/etc/asterisk/telium/pin-' . $r['pin_set_id'] . '.txt)');
            }

            $b->same("Dial(PJSIP/{$numero}@{$tronco},60,T)");

            if ($r['tronco_falha_nome']) {
                $falha = $this->identificador((string) $r['tronco_falha_nome']);
                $b->same("NoOp(Tentando tronco reserva {$r['tronco_falha_nome']})")
                  ->same("Dial(PJSIP/{$numero}@{$falha},60,T)");
            }

            $b->same('Hangup()');
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function rotasEntrada(): string
    {
        $b = $this->cabecalho('Contexto: rotas de entrada (DID → destino)')
                  ->contexto('telium-entrada');

        $rotas = Bd::todos('SELECT * FROM rotas_entrada WHERE ativo = 1 ORDER BY ordem, id');

        foreach ($rotas as $r) {
            $b->branco()
              ->comentario("{$r['did']} — {$r['descricao']} → "
                  . $this->destino->descricao($r['destino_tipo'], $r['destino_valor']))
              ->exten($r['did'], "NoOp(Rota de entrada: {$r['descricao']})")
              ->same('Set(CDR(direcao)=entrada)')
              ->same('Set(CDR(tronco)=${TELIUM_TRONCO})')
              ->same('GoSub(sub-listanegra,s,1)');

            if ((int) $r['gravar'] === 1) {
                $b->same('Answer()')
                  ->same('GoSub(sub-gravar,s,1(entrada))');
            }

            $b->apps($this->destino->linhas($r['destino_tipo'], $r['destino_valor']));
        }

        return $b->texto();
    }

    private function identificador(string $nome): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $nome) ?? $nome;
    }
}
