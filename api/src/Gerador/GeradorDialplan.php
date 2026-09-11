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

    public function __construct()
    {
        $lista = Bd::todos('SELECT * FROM ramais WHERE ativo = 1 ORDER BY numero');
        $this->ramais = array_column($lista, null, 'numero');

        $personalizados = array_column(
            Bd::todos('SELECT * FROM destinos_personalizados WHERE ativo = 1'),
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
            'extensions.ura.conf'     => $this->uras(),
            'extensions.saida.conf'   => $this->rotasSaida(),
            'extensions.entrada.conf' => $this->rotasEntrada(),
        ];
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
            'SELECT ln.*, a.arquivo AS audio
               FROM lista_negra ln
          LEFT JOIN audios a ON a.id = ln.audio_id
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
            if (($f['audio_entrada'] ?? '') !== '') {
                $b->same('Playback(' . $f['audio_entrada'] . ')');
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
     * Um contexto de confirmação por fila. O membro da fila é um canal
     * Local que entra aqui, e o U() do Dial roda a sub-rotina que pede o
     * dígito no canal de quem vai atender.
     */
    private function confirmacoesDeFila(): string
    {
        $b = $this->cabecalho('Contextos: confirmação de atendimento nas filas');

        $filas = Bd::todos(
            'SELECT numero, nome, audio_agente FROM filas
              WHERE ativo = 1 AND confirmar_atendimento = 1 ORDER BY numero'
        );

        if ($filas === []) {
            return $b->comentario('nenhuma fila com confirmação de atendimento')->texto();
        }

        foreach ($filas as $f) {
            $audio = (string) ($f['audio_agente'] ?? '');

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
            $prompt = ($p['audio_pergunta'] ?? '') !== '' ? (string) $p['audio_pergunta'] : 'beep';

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

            if (($p['audio_obrigado'] ?? '') !== '') {
                $b->same('Playback(' . $p['audio_obrigado'] . ')');
            } else {
                $b->same('Playback(auth-thankyou)');
            }

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
            $b->comentario(str_repeat('-', 62))
              ->comentario("URA {$u['id']} — {$u['nome']}")
              ->comentario(str_repeat('-', 62))
              ->contexto("telium-ura-{$u['id']}")
              ->exten('s', "NoOp(URA {$u['nome']})")
              ->same('Answer()')
              ->same('Wait(1)')
              ->same('Set(TENTATIVA=0)')
              ->same('Set(TENTATIVA=$[${TENTATIVA} + 1])', 'menu')
              ->same("GotoIf(\$[\${TENTATIVA} > {$tentativas}]?falha)")
              ->same("Background({$u['audio']})")
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
                  ->exten($o['tecla'], "NoOp({$o['rotulo']})");

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

            $b->comentario('opção inválida e tempo esgotado')
              ->exten('i', 'Playback(invalid)')
              ->same('Goto(s,menu)')
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
