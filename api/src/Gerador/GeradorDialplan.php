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
            'extensions.recursos.conf'   => $this->codigosRecurso(),
            'extensions.listanegra.conf' => $this->listaNegra(),
            'extensions.allowlist.conf'  => $this->listaPermitida(),
            'extensions.ramais.conf'  => $this->ramais(),
            'extensions.grupos.conf'  => $this->grupos(),
            'extensions.filas.conf'   => $this->filas(),
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
    private function codigosRecurso(): string
    {
        $b = $this->cabecalho('Contexto: códigos de recurso')->contexto('telium-recursos');

        $codigos = Bd::todos('SELECT * FROM codigos_recurso WHERE ativo = 1 ORDER BY categoria, codigo');
        $porChave = array_column($codigos, null, 'chave');

        // Cada chave sabe o que faz; o código discado é configurável.
        $acoes = [
            'eco' => ['Answer()', 'Wait(1)', 'Playback(demo-echotest)', 'Echo()', 'Hangup()'],
            'hora' => ['Answer()', 'Wait(1)', 'SayUnixTime(,${TELIUM_TZ},HM)', 'Hangup()'],
            'meu_ramal' => ['Answer()', 'Wait(1)', 'SayDigits(${CALLERID(num)})', 'Hangup()'],
            'vm_proprio' => ['Answer()', 'VoiceMailMain(${CALLERID(num)}@telium)', 'Hangup()'],
            'vm_outro' => ['Answer()', 'VoiceMailMain(@telium)', 'Hangup()'],
            'captura' => ['Pickup()', 'Hangup()'],
            'diretorio' => ['Answer()', 'Directory(telium,interno,f)', 'Hangup()'],
            'estacionar' => ['Park()', 'Hangup()'],
            'dnd_ligar' => [
                'Answer()',
                'Set(DB(dnd/${CALLERID(num)})=1)',
                'Playback(activated)',
                'Hangup()',
            ],
            'dnd_desligar' => [
                'Answer()',
                'Noop(${DB_DELETE(dnd/${CALLERID(num)})})',
                'Playback(de-activated)',
                'Hangup()',
            ],
            'sigame_desligar' => [
                'Answer()',
                'Noop(${DB_DELETE(sigame/${CALLERID(num)})})',
                'Playback(de-activated)',
                'Hangup()',
            ],
            'gravar_alterna' => ['Noop(Alternar gravação)', 'Return()'],
        ];

        foreach ($codigos as $c) {
            $chave = (string) $c['chave'];
            $codigo = (string) $c['codigo'];

            $b->branco()->comentario("{$codigo} — {$c['nome']}");

            // Códigos que recebem um argumento discado depois do prefixo
            if ($chave === 'captura_dir') {
                $b->exten("_{$codigo}X.", 'NoOp(Captura direta)')
                  ->same('Pickup(${EXTEN:' . strlen($codigo) . '}@PICKUPMARK)')
                  ->same('Hangup()');
                continue;
            }
            if ($chave === 'sigame_ligar') {
                $b->exten("_{$codigo}X.", 'Answer()')
                  ->same('Set(DB(sigame/${CALLERID(num)})=${EXTEN:' . strlen($codigo) . '})')
                  ->same('Playback(activated)')
                  ->same('Hangup()');
                continue;
            }

            $passos = $acoes[$chave] ?? ['NoOp(Código sem ação definida: ' . $chave . ')', 'Hangup()'];
            $b->exten($codigo, "NoOp({$c['nome']})")->apps($passos);
        }

        if ($codigos === []) {
            $b->comentario('nenhum código de recurso ativo');
        }

        return $b->texto();
    }

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

        foreach (Bd::todos('SELECT * FROM filas WHERE ativo = 1 ORDER BY numero') as $f) {
            $b->branco()
              ->comentario("{$f['numero']} — {$f['nome']} ({$f['estrategia']})")
              ->exten($f['numero'], "NoOp(Fila {$f['nome']})")
              ->same("Set(CDR(fila)={$f['numero']})")
              ->same('Answer()');

            if ((int) $f['gravar'] === 1) {
                $b->same('GoSub(sub-gravar,s,1(entrada))');
            }

            $b->same(sprintf('Queue(%s,tT,,,%d)', $f['numero'], (int) $f['max_espera']))
              ->apps($this->destino->linhas($f['destino_estouro_tipo'], $f['destino_estouro_valor']));
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
