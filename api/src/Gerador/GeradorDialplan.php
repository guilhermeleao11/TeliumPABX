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
        $this->destino = new Destino($this->ramais);
    }

    /** @return array<string,string> */
    public function gerar(): array
    {
        return [
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
              ->same('Set(CDR(tronco)=${TELIUM_TRONCO})');

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
