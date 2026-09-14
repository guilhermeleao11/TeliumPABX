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
            'extensions.condicoes.conf' => $this->condicoesHorarias(),
            'extensions.estacionamento.conf' => $this->estacionamento(),
            'extensions.ura.conf'     => $this->uras(),
            'extensions.saida.conf'   => $this->rotasSaida(),
            'extensions.entrada.conf' => $this->rotasEntrada(),
            'extensions.paging.conf'  => $this->paging(),
            'extensions.disa.conf'    => $this->disa(),
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
              ->same('Set(CDR(direcao)=interna)')
              ->same("Set(__TELIUM_DESTINO={$numero})");

            // Quem disca está fazendo uma chamada interna, então vale a
            // regra 4 do ramal de origem — ela chega no canal, no
            // TELIUM_GRAV do endpoint. A regra de quem recebe é escrita
            // aqui como literal, porque o destino já é conhecido.
            $b->same(sprintf(
                'GoSub(sub-decidir-gravacao,s,1(4,%s,interna))',
                $this->regraDeQuemRecebe($r)
            ));

            // Não perturbe, siga-me e desvios ficam todos no sub-ramal:
            // ele lê a base do Asterisk, que é onde o código de recurso
            // discado no telefone também escreve. Decidir aqui faria o
            // console e o telefone discordarem.
            $b->apps($this->destino->linhas('ramal', $numero));
        }

        return $b->texto();
    }

    /**
     * A regra de gravação do ramal que recebe uma chamada interna. A
     * coluna antiga "gravar" continua valendo para quem nunca mexeu nas
     * quatro novas.
     */
    private function regraDeQuemRecebe(array $r): string
    {
        $regra = (string) ($r['grav_int_entrada'] ?? 'indiferente');
        if ($regra !== 'indiferente') {
            return $regra;
        }

        return in_array($r['gravar'], ['ambas', 'entrada'], true) ? 'sim' : 'nao';
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
              ->same("Set(__TELIUM_DESTINO={$numero})")
              ->same('Answer()');

            // Na fila quem manda é a fila, mas "nunca" no ramal ainda vence.
            // Regra 1 quando a chamada veio da rua, regra 4 quando foi um
            // ramal que discou a fila — o canal diz qual dos dois é.
            $b->same('GoSub(sub-decidir-gravacao,s,1(${IF($["${TELIUM_RAMAL}" != ""]?4:1)},'
                . ((int) $f['gravar'] === 1 ? 'sim' : 'nao') . ',entrada))');

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
     * Estacionamento: para onde vai a chamada que ninguém retomou.
     *
     * Só os lotes que não voltam para quem estacionou precisam disto —
     * nos outros, o próprio Asterisk toca de volta na origem.
     */
    private function estacionamento(): string
    {
        $b = $this->cabecalho('Contextos: chamadas estacionadas que estouraram o tempo');

        $lotes = Bd::todos(
            'SELECT * FROM estacionamentos WHERE ativo = 1 AND volta_para_origem = 0 ORDER BY nome'
        );

        if ($lotes === []) {
            return $b->comentario('todos os lotes devolvem a chamada para quem estacionou')->texto();
        }

        foreach ($lotes as $l) {
            $nome = (int) $l['padrao'] === 1 ? 'default' : (string) $l['nome'];
            $linhas = $this->destino->linhas($l['destino_tipo'], $l['destino_valor']);

            // O Asterisk tenta a extensão com o número da vaga e cai em 's'
            // quando ela não existe; as duas levam ao mesmo destino.
            $b->branco()
              ->comentario("Lote {$l['nome']}")
              ->contexto("telium-volta-{$nome}")
              ->exten('s', "NoOp(Chamada estacionada sem ninguém retomar — lote {$l['nome']})")
              ->apps($linhas)
              ->exten('_X.', 'Goto(s,1)');
        }

        return $b->texto();
    }

    /**
     * Condições horárias.
     *
     * Cada condição pergunta ao relógio do servidor se estamos dentro de
     * alguma faixa do grupo e manda a chamada para um destino ou outro.
     * O código *27 pode forçar aberto ou fechado, e essa marca vence o
     * relógio — é como se libera um feriado sem mexer na configuração.
     */
    private function condicoesHorarias(): string
    {
        $b = $this->cabecalho('Contexto: condições horárias')->contexto('telium-condicoes');

        $condicoes = Bd::todos(
            'SELECT c.*, g.nome AS grupo_nome
               FROM condicoes_horarias c
          LEFT JOIN grupos_horario g ON g.id = c.grupo_horario_id
              WHERE c.ativo = 1 ORDER BY c.id'
        );

        if ($condicoes === []) {
            return $b->comentario('nenhuma condição horária ativa')->texto();
        }

        foreach ($condicoes as $c) {
            $id = (int) $c['id'];
            $faixas = Bd::todos(
                'SELECT * FROM grupo_horario_faixas WHERE grupo_id = ? ORDER BY ordem, id',
                [$c['grupo_horario_id']]
            );

            $b->branco()
              ->comentario("{$id} — {$c['nome']} (grupo: " . ($c['grupo_nome'] ?? 'sem grupo') . ')')
              ->exten((string) $id, "NoOp(Condição horária: {$c['nome']})")
              ->same('Set(TELIUM_FORCA=${DB(condicao/' . $id . ')})')
              ->same('GotoIf($["${TELIUM_FORCA}" = "aberto"]?dentro)')
              ->same('GotoIf($["${TELIUM_FORCA}" = "fechado"]?fora)');

            if ($faixas === []) {
                $b->comentario('  grupo sem faixas: nunca está dentro');
            }

            foreach ($faixas as $f) {
                $regra = $this->faixaDeHorario($f);
                $b->comentario("  faixa: {$regra}")
                  ->same("GotoIfTime({$regra}?dentro)");
            }

            // Sem casar com nenhuma faixa, a chamada segue para "fora".
            $fora = $this->destino->linhas($c['destino_fora_tipo'], $c['destino_fora_valor']);
            $b->same(array_shift($fora), 'fora')->apps($fora);

            $dentro = $this->destino->linhas($c['destino_dentro_tipo'], $c['destino_dentro_valor']);
            $b->same(array_shift($dentro), 'dentro')->apps($dentro);
        }

        return $b->texto();
    }

    /**
     * Uma faixa no formato do GotoIfTime:
     *   <horas>,<dias da semana>,<dias do mês>,<meses>
     * O asterisco em qualquer posição quer dizer "tanto faz".
     */
    private function faixaDeHorario(array $f): string
    {
        // Dia da semana vem 0=domingo, e o mês vem 1=janeiro: por isso o
        // mapa dos meses começa no índice 1, e não no 0.
        $semana = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $meses = [1 => 'jan', 'feb', 'mar', 'apr', 'may', 'jun',
                       'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        $intervalo = static function (mixed $de, mixed $ate, ?array $mapa = null): string {
            if ($de === null || $de === '') {
                return '*';
            }
            $ini = $mapa === null ? (string) $de : ($mapa[(int) $de] ?? '*');
            $fim = ($ate === null || $ate === '')
                ? $ini
                : ($mapa === null ? (string) $ate : ($mapa[(int) $ate] ?? $ini));

            return $ini === $fim ? $ini : "{$ini}-{$fim}";
        };

        $horas = ($f['hora_inicio'] ?? null) === null || ($f['hora_fim'] ?? null) === null
            ? '*'
            : substr((string) $f['hora_inicio'], 0, 5) . '-' . substr((string) $f['hora_fim'], 0, 5);

        return implode(',', [
            $horas,
            $intervalo($f['dia_semana_inicio'] ?? null, $f['dia_semana_fim'] ?? null, $semana),
            $intervalo($f['dia_mes_inicio'] ?? null, $f['dia_mes_fim'] ?? null),
            $intervalo($f['mes_inicio'] ?? null, $f['mes_fim'] ?? null, $meses),
        ]);
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

    /**
     * Megafonia: um número que abre o viva-voz de vários aparelhos.
     *
     * O Page() já faz o trabalho; o que muda de grupo para grupo é quem
     * toca, se todos falam ou só quem chamou, e se a chamada em curso é
     * interrompida.
     */
    private function paging(): string
    {
        $b = $this->cabecalho('Contexto: megafonia e interfonia')->contexto('telium-paging');

        $grupos = $this->consulta('SELECT * FROM grupos_paging WHERE ativo = 1 ORDER BY numero');
        if ($grupos === []) {
            return $b->comentario('nenhum grupo de megafonia cadastrado')->texto();
        }

        foreach ($grupos as $g) {
            $ramais = array_filter(array_map('trim', explode('-', (string) $g['ramais'])));
            if ($ramais === []) {
                continue;
            }

            $canais = implode('&', array_map(
                static fn (string $r): string => 'PJSIP/' . preg_replace('/[^0-9]/', '', $r),
                $ramais
            ));

            // d = todos falam (interfonia), sem d só quem chamou é ouvido.
            // i = ignora quem recusa megafonia. q = sem o bipe do Asterisk.
            $opcoes = 'q'
                . ((int) $g['duplex'] === 1 ? 'd' : '')
                . ((int) $g['forcar'] === 1 ? 'i' : '');

            $b->branco()
              ->comentario("{$g['numero']} — {$g['nome']} ("
                  . count($ramais) . ' aparelho' . (count($ramais) > 1 ? 's' : '')
                  . ((int) $g['duplex'] === 1 ? ', todos falam' : '') . ')')
              ->exten((string) $g['numero'], "NoOp(Megafonia: {$g['nome']})")
              ->same('Set(CDR(direcao)=interna)')
              ->same('Answer()')
              ->same('Wait(1)');

            $anuncio = $this->audioDoAnuncio($g['anuncio_id'] ?? 0);
            if ($anuncio !== '') {
                $b->same("Playback({$anuncio})");
            }

            $b->same('Set(CALLERID(name)=Megafonia ${CALLERID(num)})')
              ->same(sprintf('Page(%s,%s,%d)', $canais, $opcoes, (int) $g['duracao_max']))
              ->same('Hangup()');
        }

        return $b->texto();
    }

    /**
     * DISA: ligar de fora e discar como se estivesse na mesa.
     *
     * É o recurso mais explorado por fraude de tarifação que existe num
     * PABX, então a senha não é opcional e o contexto é o do cadastro —
     * quem quiser liberar interurbano põe um contexto próprio, de
     * propósito, em vez de ganhar isso por descuido.
     */
    private function disa(): string
    {
        $b = $this->cabecalho('Contextos: DISA');

        $lista = $this->consulta('SELECT * FROM disa WHERE ativo = 1 ORDER BY id');
        if ($lista === []) {
            return $b->contexto('telium-disa')->comentario('nenhuma DISA cadastrada')->texto();
        }

        // Contexto de entrada, para a rota apontar por id.
        $b->contexto('telium-disa');
        foreach ($lista as $d) {
            $b->exten("disa-{$d['id']}", "Goto(telium-disa-{$d['id']},s,1)");
        }

        foreach ($lista as $d) {
            $id = (int) $d['id'];
            $b->branco()
              ->comentario(str_repeat('-', 62))
              ->comentario("DISA {$id} — {$d['nome']}")
              ->comentario(str_repeat('-', 62))
              ->contexto("telium-disa-{$id}")
              ->exten('s', "NoOp(DISA: {$d['nome']})");

            if ((int) $d['responder'] === 1) {
                $b->same('Answer()')->same('Wait(1)');
            }

            // O CID de saída é do cadastro da DISA: sem ele a chamada
            // sairia com o número de quem ligou de fora, que a operadora
            // recusa.
            $cid = $this->soNumero((string) ($d['cid_saida'] ?? ''));
            if ($cid !== '') {
                $b->same("Set(CALLERID(num)={$cid})")
                  ->same("Set(CALLERID(name)={$cid})");
            }

            $b->same(sprintf(
                'DISA(%s,%s,,,%d)',
                $this->soNumero((string) $d['senha']),
                $this->identificador((string) $d['contexto']),
                max(3, (int) $d['tempo_digito'])
            ))
              ->same('NoOp(DISA encerrada)')
              ->same('Hangup()');
        }

        return $b->texto();
    }

    /**
     * Consulta que tolera tabela ausente.
     *
     * Um módulo novo chega com o seu script de banco; até ele ser
     * aplicado, o gerador não pode parar de escrever todo o resto.
     *
     * @return list<array<string,mixed>>
     */
    private function consulta(string $sql): array
    {
        try {
            return Bd::todos($sql);
        } catch (\Throwable) {
            return [];
        }
    }

    // ---------------------------------------------------------------
    private function rotasSaida(): string
    {
        $b = $this->cabecalho('Contexto: rotas de saída (ordem de precedência)')
                  ->contexto('telium-saida');

        $rotas = Bd::todos(
            'SELECT r.*, t.nome AS tronco_nome, t.cid_saida AS tronco_cid,
                    tf.nome AS tronco_falha_nome, tf.cid_saida AS tronco_falha_cid
               FROM rotas_saida r
               JOIN troncos t  ON t.id = r.tronco_id
          LEFT JOIN troncos tf ON tf.id = r.tronco_falha_id
              WHERE r.ativo = 1
           ORDER BY r.ordem, r.id'
        );

        if ($rotas === []) {
            return $b->comentario('nenhuma rota de saída ativa')->texto();
        }

        foreach ($rotas as $r) {
            $tronco = $this->identificador((string) $r['tronco_nome']);
            // O rótulo sai do id porque dois padrões diferentes podem
            // virar o mesmo texto depois de tirar os símbolos (_00X. e
            // _00X viram "00X") e os labels colidiriam dentro do contexto.
            $rotulo = 'r' . $r['id'];

            // Atenção ao prefixo "0": com um teste de verdadeiro simples o
            // PHP o trata como vazio e o dígito seguia para a operadora.
            $remover = (string) ($r['prefixo_remover'] ?? '');
            $numero = $remover !== ''
                ? '${EXTEN:' . strlen($remover) . '}'
                : '${EXTEN}';
            $adicionar = (string) ($r['prefixo_adicionar'] ?? '');
            if ($adicionar !== '') {
                $numero = $adicionar . $numero;
            }

            $b->branco()
              ->comentario("Ordem {$r['ordem']} — {$r['nome']} ({$r['classe']}) via {$r['tronco_nome']}")
              ->exten($r['padrao'], "NoOp(Rota de saída: {$r['nome']})")
              ->same('Set(CDR(direcao)=saida)')
              ->same('Set(__TELIUM_DESTINO=${EXTEN})')
              ->same("Set(CDR(tronco)={$r['tronco_nome']})")
              ->same("Set(__TELIUM_CLASSE={$r['classe']})")
              ->same("GoSub(sub-permissao,s,1({$r['classe']}))")
              ->same('GoSub(sub-limite-saida,s,1)');

            if ($r['pin_set_id']) {
                // A opção "a" grava o PIN digitado no accountcode, que é
                // o que permite cobrar a ligação de um centro de custo.
                $noCdr = (int) (Bd::valor('SELECT no_cdr FROM pin_sets WHERE id = ?',
                                          [$r['pin_set_id']]) ?? 1);
                $b->same(sprintf(
                    'Authenticate(%s/pin-%d.txt%s)',
                    $this->diretorioGerado(),
                    (int) $r['pin_set_id'],
                    $noCdr === 1 ? '' : ',a'
                ));
            }

            // A gravação do sainte é decidida pelo ramal que discou.
            // Chamada externa feita: regra 2 do ramal que discou. A rota
            // não tem opinião própria, então o pedido é "não".
            $b->same('GoSub(sub-decidir-gravacao,s,1(2,nao,saida))');

            // Sem isto o canal sai com o número interno do ramal no From
            // e a operadora recusa. O CID do ramal manda; o do tronco é
            // o padrão da casa.
            $b->same('GoSub(sub-cid-saida,s,1('
                   . $this->soNumero((string) ($r['tronco_cid'] ?? '')) . '))');

            $b->same("Dial(PJSIP/{$numero}@{$tronco},60,tT)")
              ->same('NoOp(Tronco principal: ${DIALSTATUS})');

            if ($r['tronco_falha_nome']) {
                // Só cai no reserva quando o principal não completou. Sem
                // esta checagem, uma chamada atendida e encerrada seguia
                // para a próxima prioridade e discava de novo.
                $falha = $this->identificador((string) $r['tronco_falha_nome']);
                $b->same('GotoIf($["${DIALSTATUS}" = "ANSWER" | "${DIALSTATUS}" = "BUSY"'
                       . ' | "${DIALSTATUS}" = "CANCEL"]?fim-' . $rotulo . ')')
                  ->same("NoOp(Tentando o tronco reserva {$r['tronco_falha_nome']})")
                  ->same("Set(CDR(tronco)={$r['tronco_falha_nome']})")
                  // O reserva costuma ser de outra operadora, com outro
                  // número contratado.
                  ->same('GoSub(sub-cid-saida,s,1('
                       . $this->soNumero((string) ($r['tronco_falha_cid'] ?? '')) . '))')
                  ->same("Dial(PJSIP/{$numero}@{$falha},60,tT)")
                  ->same('NoOp(Tronco reserva: ${DIALSTATUS})');
            }

            // Tradução do motivo para quem está no telefone: sem isto, a
            // chamada apenas cai e ninguém sabe se foi bloqueio ou falha.
            $b->same('NoOp(Encerrando a chamada)', 'fim-' . $rotulo)
              ->same('GotoIf($["${DIALSTATUS}" = "BUSY"]?ocupado-' . $rotulo . ')')
              ->same('GotoIf($["${DIALSTATUS}" = "CHANUNAVAIL" | "${DIALSTATUS}" = "CONGESTION"]'
                   . '?semlinha-' . $rotulo . ')')
              ->same('Hangup()')
              ->same('Busy(10)', 'ocupado-' . $rotulo)
              ->same('Hangup()')
              ->same('NoOp(Nenhum tronco atendeu)', 'semlinha-' . $rotulo)
              ->same('Congestion(10)')
              ->same('Hangup()');
        }

        return $b->texto();
    }

    // ---------------------------------------------------------------
    private function rotasEntrada(): string
    {
        $b = $this->cabecalho('Contexto: rotas de entrada (DID → destino)')
                  ->contexto('telium-entrada');

        $rotas = Bd::todos('SELECT * FROM rotas_entrada WHERE ativo = 1 ORDER BY ordem, id');

        // Um ramal com DID próprio vira rota sozinho. É o caminho curto
        // para "este número cai direto neste ramal", sem obrigar quem
        // cadastra a criar uma rota à parte — e é o que dá função aos
        // campos DID e descrição do cadastro do ramal.
        $jaTem = array_map(static fn (array $r): string => trim((string) $r['did']), $rotas);
        foreach ($this->ramais as $r) {
            $did = trim((string) ($r['did'] ?? ''));
            if ($did === '' || in_array($did, $jaTem, true)) {
                continue;       // rota explícita tem sempre a última palavra
            }

            $rotas[] = [
                'did'           => $did,
                'descricao'     => ($r['did_descricao'] ?? '') ?: "Ramal {$r['numero']}",
                'destino_tipo'  => 'ramal',
                'destino_valor' => $r['numero'],
                'gravar'        => in_array($r['gravar'] ?? 'nao', ['ambas', 'entrada'], true) ? 1 : 0,
                'cid_entrada'   => $r['cid_entrada'] ?? null,
                'ordem'         => 500,
            ];
        }

        // A rota coringa atende qualquer DID, então precisa ser a última:
        // o Asterisk escolhe o padrão mais específico, mas a leitura do
        // arquivo fica muito mais clara com ela no fim.
        usort($rotas, fn ($a, $z) => $this->ehCoringa($a['did']) <=> $this->ehCoringa($z['did']));

        // Um DID escrito como padrão (_X.) já pega o que não tem rota
        // própria; nesse caso a rede de segurança abaixo seria ruído.
        $temCoringa = false;

        foreach ($rotas as $r) {
            $coringa = $this->ehCoringa($r['did']);
            $temCoringa = $temCoringa || $coringa || str_starts_with(trim((string) $r['did']), '_');
            // "_X." e não "_.": o próprio Asterisk desaconselha o
            // segundo, que casa até com o que não é número.
            $padrao = $coringa ? '_X.' : $r['did'];
            $titulo = $coringa ? 'qualquer DID' : (string) $r['did'];

            $b->branco()
              ->comentario("{$titulo} — {$r['descricao']} → "
                  . $this->destino->descricao($r['destino_tipo'], $r['destino_valor']))
              ->exten($padrao, "NoOp(Rota de entrada: {$r['descricao']})")
              ->same('Set(CDR(direcao)=entrada)')
              ->same('Set(__TELIUM_DESTINO=${EXTEN})')
              ->same('Set(CDR(tronco)=${TELIUM_TRONCO})')
              ->same('GoSub(sub-listanegra,s,1)');

            // Rótulo na frente de quem ligou, para o atendente saber por
            // qual número a chamada entrou antes de tirar o fone do gancho.
            $rotulo = trim((string) ($r['cid_entrada'] ?? ''));
            if ($rotulo !== '') {
                $b->same('Set(CALLERID(name)=' . $this->semParenteses($rotulo)
                       . ' ${CALLERID(name)})');
            }

            if ((int) $r['gravar'] === 1) {
                $b->same('Answer()')
                  ->same('GoSub(sub-decidir-gravacao,s,1(1,sim,entrada))');
            }

            $b->apps($this->destino->linhas($r['destino_tipo'], $r['destino_valor']));
        }

        // Sem isto uma chamada com DID fora da lista morre em silêncio e o
        // console só diz "invalid extension" — ninguém descobre que a
        // operadora mudou o formato do número entregue.
        if (!$temCoringa) {
            $b->branco()
              ->comentario('DID sem rota cadastrada — evita a chamada morrer calada')
              ->exten('_X.', 'NoOp(DID ${EXTEN} chegou pelo tronco ${TELIUM_TRONCO} e não tem rota de entrada)')
              ->same('Answer()')
              ->same('Wait(1)')
              ->same('Playback(ss-noservice)')
              ->same('Hangup(1)');
        }

        return $b->texto();
    }

    /** DID em branco, "*" ou "qualquer" quer dizer: vale para todos. */
    private function ehCoringa(?string $did): bool
    {
        return in_array(trim((string) $did), ['', '*', 's', 'qualquer'], true);
    }

    /** Onde os arquivos gerados ficam — o mesmo que o Gerador usa. */
    private function diretorioGerado(): string
    {
        return rtrim(
            (string) \Telium\Suporte\Ambiente::get('ASTERISK_GERADO_DIR', '/etc/asterisk/telium'),
            '/'
        );
    }

    private function identificador(string $nome): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $nome) ?? $nome;
    }

    /**
     * Texto que entra dentro de uma aplicação do dialplan.
     *
     * Parêntese fecha a aplicação antes da hora e vírgula vira outro
     * argumento: os dois quebram a linha de formas difíceis de ver.
     */
    private function semParenteses(string $texto): string
    {
        return trim((string) preg_replace('/[(),|\[\]]/', ' ', $texto));
    }

    /**
     * CID vira argumento de GoSub: vírgula, parêntese ou ponto e vírgula
     * ali dentro quebram a linha inteira do dialplan.
     */
    private function soNumero(string $texto): string
    {
        return preg_replace('/[^0-9+]/', '', $texto) ?? '';
    }
}
