<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Gera queues.conf.
 *
 * Cada opção aqui existe porque muda o comportamento de verdade no
 * app_queue. O que o Asterisk oferece mas não faz diferença prática — ou
 * que é armadilha, como penaltymemberslimit — fica de fora de propósito:
 * a tela já tem opção demais para incluir coisa que não muda nada.
 */
final class GeradorFilas
{
    /**
     * joinempty / leavewhenempty do Asterisk.
     *
     * joinempty = yes  → o cliente entra mesmo sem ninguém logado
     * joinempty = no   → não entra (no Asterisk: penalty,paused,invalid)
     * leavewhenempty = yes → quem já está na espera sai se a fila esvaziar
     * strict → o mesmo, contando também o indisponível como ausente
     *          (penalty,paused,invalid,unavailable)
     */
    private const VAZIA = [
        'sim'     => 'yes',
        'nao'     => 'no',
        'estrito' => 'strict',
    ];

    /** @var array<int,string> id do anúncio => arquivo que ele toca */
    private array $anuncios = [];

    /** @return array<string,string> */
    public function gerar(): array
    {
        // Nenhuma coluna guarda nome de arquivo: a fila aponta para um
        // anúncio, e é aqui que ele vira o arquivo que o Asterisk toca.
        foreach (Bd::todos('SELECT a.id, s.arquivo FROM anuncios a
                              JOIN audios s ON s.id = a.audio_id
                             WHERE a.ativo = 1') as $a) {
            $this->anuncios[(int) $a['id']] = Som::prompt($a['arquivo']);
        }

        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->comentario('O [general] fica no queues.conf estático, que inclui este arquivo')
            ->comentario('Personalizações vão em telium/queues_custom.conf')
            ->branco();

        $regras = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Ampliação de habilidade das filas de call center')
            ->branco();

        foreach (Bd::todos('SELECT * FROM filas WHERE ativo = 1 ORDER BY numero') as $f) {
            $this->fila($b, $f);
            $this->regra($regras, $f);
        }

        $b->crua('#include "telium/queues_custom.conf"');

        return ['queues.conf' => $b->texto(), 'queuerules.conf' => $regras->texto()];
    }

    /**
     * Ampliação de habilidade: começa só com o nível 0 e, a cada N
     * segundos de espera, a fila passa a oferecer a chamada a mais um
     * nível. O começo em 0 é posto no dialplan (QUEUE_MAX_PENALTY),
     * porque a regra só age depois do primeiro intervalo.
     */
    private function regra(Bloco $b, array $f): void
    {
        $passo = (int) ($f['ampliar_segundos'] ?? 0);
        if ((int) $f['callcenter'] !== 1 || $passo <= 0) {
            return;
        }

        $b->crua('[' . self::nomeDaRegra((string) $f['numero']) . ']');
        for ($nivel = 1; $nivel <= max(1, (int) $f['ampliar_ate']); $nivel++) {
            $b->crua(sprintf('penaltychange => %d,%d', $passo * $nivel, $nivel));
        }
        $b->branco();
    }

    public static function nomeDaRegra(string $fila): string
    {
        return "telium-{$fila}";
    }

    private function fila(Bloco $b, array $f): void
    {
        $numero = (string) $f['numero'];

        $b->comentario("Fila {$numero} — {$f['nome']}"
            . ((int) $f['callcenter'] === 1 ? ' (call center)' : ''))
          ->crua("[{$numero}]")
          ->crua("strategy = {$f['estrategia']}")
          ->crua("timeout = {$f['timeout_agente']}")
          ->crua("retry = {$f['retry']}")
          ->crua("wrapuptime = {$f['wrapuptime']}")
          ->crua("servicelevel = {$f['sla_segundos']}")
          ->crua("musicclass = {$f['musica_espera']}");

        // Sussurro: o Asterisk toca este arquivo para quem vai atender,
        // antes de juntar as pontas. É como o agente sabe de que fila veio.
        //
        // Com confirmação ligada ele não entra aqui: o mesmo áudio já é a
        // pergunta do "aperte 1 para atender", e tocar duas vezes seria
        // pior do que não tocar.
        $sussurro = $this->anuncio($f['anuncio_agente_id'] ?? 0);
        if ($sussurro !== '' && (int) $f['confirmar_atendimento'] !== 1) {
            $b->crua("announce = {$sussurro}");
        }

        // Anúncios ao cliente que espera
        $b->crua('announce-position = ' . ((int) $f['anuncio_posicao'] === 1 ? 'yes' : 'no'))
          ->crua('announce-holdtime = ' . ((int) $f['anuncio_espera'] === 1 ? 'yes' : 'no'));

        if ((int) $f['anuncio_posicao'] === 1 || (int) $f['anuncio_espera'] === 1) {
            $b->crua('announce-frequency = ' . max(10, (int) $f['anuncio_frequencia']));
        }

        $periodico = $this->anuncio($f['anuncio_periodico_id'] ?? 0);
        if ($periodico !== '') {
            $b->crua("periodic-announce = {$periodico}")
              ->crua('periodic-announce-frequency = ' . max(10, (int) $f['periodico_segundos']));
        }

        // Quem entra, quem fica e quantos cabem.
        //
        // Com ampliação de habilidade a chamada começa só no nível 0, e o
        // "no"/"strict" do Asterisk contam como ausente quem está acima do
        // nível permitido ("penalty"). Com só agentes de nível 1 logados,
        // toda chamada era recusada como fila vazia — e a ampliação nunca
        // chegava a acontecer. Aqui as mesmas regras, sem o "penalty".
        $ampliando = (int) $f['callcenter'] === 1 && (int) ($f['ampliar_segundos'] ?? 0) > 0;
        $vazia = static function (string $valor) use ($ampliando): string {
            $v = self::VAZIA[$valor] ?? 'no';
            if (!$ampliando) {
                return $v;
            }

            return match ($v) {
                'no'     => 'paused,invalid',
                'strict' => 'paused,invalid,unavailable',
                default  => $v,
            };
        };
        $b->crua('joinempty = ' . $vazia((string) $f['entrar_vazia']))
          ->crua('leavewhenempty = ' . $vazia((string) $f['sair_vazia']))
          ->crua('maxlen = ' . (int) $f['max_chamadas'])
          ->crua('weight = ' . (int) $f['peso'])
          ->crua('ringinuse = ' . ((int) $f['tocar_ocupado'] === 1 ? 'yes' : 'no'));

        if ((int) $f['atraso_atendimento'] > 0) {
            $b->crua("memberdelay = {$f['atraso_atendimento']}");
        }

        // Pausa automática de quem não atende: evita a chamada ficar
        // rodando num agente que saiu da mesa sem se pausar.
        $b->crua('autopause = ' . match ($f['pausa_automatica']) {
            'sim'   => 'yes',
            'todas' => 'all',
            default => 'no',
        });

        // Grava no canal de quem ligou quem atendeu (MEMBERINTERFACE e
        // afins). O retorno usa isso para saber, ao cair, se chegou a falar
        // com um agente, e a pesquisa para saber quem foi avaliado — sem
        // ela a variável nunca existia e a nota ficava sem atendente.
        $b->crua('setinterfacevar = yes');

        if ((int) $f['callcenter'] === 1) {
            if ((int) ($f['ampliar_segundos'] ?? 0) > 0) {
                $b->crua('defaultrule = ' . self::nomeDaRegra($numero));
            }
            // Tecla de retorno: a fila sai para este contexto quando o
            // cliente aperta um dígito que existe nele — e só esse.
            if (($f['retorno_tecla'] ?? '') !== '' && $f['retorno_tecla'] !== null) {
                $b->crua("context = telium-cc-retorno-{$numero}");
            }
        }

        // Nada de monitor-type aqui: o app_queue do Asterisk 22 não tem
        // mais gravação própria, e ela já acontece no dialplan, em
        // sub-gravar, que é o único lugar que sabe onde o arquivo vai.

        // Com confirmação, o membro é um canal Local que passa pelo
        // contexto de confirmação da fila; /n impede o Asterisk de
        // otimizar o canal e sumir com a pergunta.
        $confirma = (int) $f['confirmar_atendimento'] === 1;

        // Fila de call center não tem membro fixo: quem está nela é quem
        // entrou (console ou *11), com o nome "Agente/<id>". Escrever um
        // membro aqui faria um ramal receber chamada sem ninguém logado.
        $fixos = (int) $f['callcenter'] === 1 ? [] : $this->agentes((int) $f['id']);

        foreach ($fixos as $a) {
            // Campos do member: canal, penalidade, nome, canal de estado e
            // se a fila pode oferecer chamada com o ramal já ocupado. O de
            // estado precisa ser o PJSIP mesmo quando o canal é um Local
            // de confirmação, senão a fila nunca enxerga o agente ocupado.
            $b->crua(sprintf(
                'member => %s,%d,%s,%s,%s',
                $confirma
                    ? "Local/{$a['numero']}@telium-confirma-{$numero}/n"
                    : "PJSIP/{$a['numero']}",
                (int) $a['penalidade'],
                str_replace(',', ' ', (string) $a['nome']),
                "PJSIP/{$a['numero']}",
                (int) ($a['estado_em_fila'] ?? 1) === 1 ? 'no' : 'yes'
            ));
        }

        $b->branco();
    }

    private function anuncio(mixed $id): string
    {
        return $this->anuncios[(int) $id] ?? '';
    }

    /**
     * Membros gravados no arquivo. Só os estáticos: o dinâmico entra e
     * sai pelo código *45 e vive na base do Asterisk, e escrevê-lo aqui
     * faria ele voltar sozinho a cada reload.
     *
     * @return array<int,array<string,mixed>>
     */
    private function agentes(int $filaId): array
    {
        return Bd::todos(
            'SELECT r.numero, r.nome, a.penalidade, r.estado_em_fila
               FROM fila_agentes a
               JOIN ramais r ON r.id = a.ramal_id
              WHERE a.fila_id = ? AND a.tipo = ? AND r.ativo = 1
           ORDER BY a.penalidade, r.numero',
            [$filaId, 'estatico']
        );
    }
}
