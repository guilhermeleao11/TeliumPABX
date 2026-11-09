<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Chamadas;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Números do painel. Tudo sai do CDR real e do estado do Asterisk —
 * base vazia devolve zeros, não dados inventados.
 */
final class Painel
{
    /** GET /api/painel/visaogeral */
    public function visaogeral(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'kpis'       => $this->kpis(),
            'volume'     => $this->volumePorHora(),
            'topRamais'  => $this->topRamais(),
            'troncos'    => $this->troncos(),
            // O registro de atividades é da auditoria, que ficou restrita a
            // quem tem admin.auditoria: aqui ele saía para todo mundo que vê
            // o painel, com usuário, IP e as tentativas de login erradas.
            'atividades' => \Telium\Dominio\Permissoes::podeModulo($req->getAttribute('allow', []), 'admin.auditoria')
                ? $this->atividades() : [],
            'atualizado' => date('c'),
        ]);
    }

    // ------------------------------------------------------------------
    /**
     * As ligações de hoje, uma vez cada (Chamadas junta os trechos do CDR):
     * contar linhas dava a fila que tocou em dois agentes como duas
     * chamadas, uma delas "perdida".
     *
     * @return list<array<string,mixed>>
     */
    private function deHoje(): array
    {
        return $this->hoje ??= iterator_to_array(Chamadas::percorrer(date('Y-m-d'), date('Y-m-d')), false);
    }

    /** @var list<array<string,mixed>>|null */
    private ?array $hoje = null;

    private function kpis(): array
    {
        $hoje = $this->deHoje();
        $total = count($hoje);
        $porDirecao = ['entrada' => 0, 'saida' => 0, 'interna' => 0];
        $entradaSemAtender = 0;
        $falado = 0;
        $conversas = 0;
        foreach ($hoje as $c) {
            $porDirecao[$c['direcao']]++;
            if ($c['direcao'] === 'entrada' && !$c['atendida']) {
                $entradaSemAtender++;
            }
            if ($c['atendida'] && $c['falado'] > 0) {
                $falado += $c['falado'];
                $conversas++;
            }
        }
        $entrada = $porDirecao['entrada'];
        $ontem = date('Y-m-d', strtotime('-1 day'));
        $totalOntem = Chamadas::contar($ontem, $ontem);
        $tma = $conversas > 0 ? (int) round($falado / $conversas) : 0;

        $variacao = $totalOntem > 0
            ? round((($total - $totalOntem) / $totalOntem) * 100, 1)
            : null;

        $ativas = $this->canaisAtivos();
        $n = static fn (int $v): string => number_format($v, 0, ',', '.');

        return [
            [
                'chave' => 'chamadas_hoje',
                'label' => 'Ligações hoje',
                'valor' => $n($total),
                'delta' => $variacao,
                'ico'   => 'phone',
                'tone'  => 'brand',
                // As três partes somam o total, e o "ontem" é contado igual.
                'rodape' => "{$n($entrada)} recebidas · {$n($porDirecao['saida'])} feitas · "
                    . "{$n($porDirecao['interna'])} internas"
                    . ($totalOntem > 0 ? " · ontem {$n($totalOntem)}" : ''),
            ],
            [
                'chave' => 'em_atendimento',
                'label' => 'Em atendimento',
                'valor' => $ativas['ok'] ? (string) $ativas['chamadas'] : '—',
                'delta' => null,
                'ico'   => 'headset',
                'tone'  => $ativas['ok'] ? 'ok' : 'warn',
                'rodape' => $ativas['ok']
                    ? $ativas['canais'] . ' canais ativos agora'
                    : 'Asterisk não respondeu',
            ],
            [
                // O abandono de fila, medido pela fila, está em Desempenho
                // de Filas; aqui é o que chegou de fora e ninguém atendeu.
                'chave' => 'abandono',
                'label' => 'Recebidas não atendidas',
                'valor' => $entrada > 0
                    ? number_format($entradaSemAtender / $entrada * 100, 1, ',', '.') . '%'
                    : '—',
                'delta' => null,
                'ico'   => 'phoneOff',
                'tone'  => 'warn',
                'rodape' => $entrada > 0
                    ? "{$n($entradaSemAtender)} de {$n($entrada)} ligações de fora"
                    : 'nenhuma ligação de fora hoje',
            ],
            [
                'chave' => 'tma',
                'label' => 'TMA — tempo médio',
                'valor' => $tma > 0 ? $this->duracao($tma) : '—',
                'delta' => null,
                'ico'   => 'clock',
                'tone'  => 'info',
                'rodape' => $tma > 0 ? "de conversa, em {$n($conversas)} ligações atendidas" : 'sem ligações atendidas',
            ],
        ];
    }

    private function volumePorHora(): array
    {
        $porHora = [];
        foreach ($this->deHoje() as $c) {
            $porHora[$c['hora']] ??= ['atendidas' => 0, 'perdidas' => 0];
            $porHora[$c['hora']][$c['atendida'] ? 'atendidas' : 'perdidas']++;
        }

        if ($porHora === []) {
            return ['labels' => [], 'series' => [], 'vazio' => true];
        }

        $primeira = (int) min(array_keys($porHora));
        $ultima = (int) max(array_keys($porHora));

        $labels = [];
        $atendidas = [];
        $perdidas = [];
        for ($h = $primeira; $h <= $ultima; $h++) {
            $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h';
            $atendidas[] = $porHora[$h]['atendidas'] ?? 0;
            $perdidas[] = $porHora[$h]['perdidas'] ?? 0;
        }

        return [
            'labels' => $labels,
            'series' => [
                ['key' => 'atendidas', 'label' => 'Atendidas', 'color' => 'var(--series-1)', 'values' => $atendidas],
                ['key' => 'perdidas',  'label' => 'Não atendidas', 'color' => 'var(--series-2)', 'values' => $perdidas],
            ],
            'vazio' => false,
        ];
    }

    /**
     * Os ramais com mais ligações atendidas hoje: as que fizeram e alguém
     * atendeu, e as que eles atenderam. Antes eram só as que o ramal fez,
     * e quem passou o dia atendendo a fila não aparecia.
     */
    private function topRamais(): array
    {
        $conta = [];
        foreach ($this->deHoje() as $c) {
            if (!$c['atendida']) {
                continue;
            }
            $ramais = $c['atenderam'];
            if ($c['ramal_origem'] !== null) {
                $ramais[] = $c['ramal_origem'];
            }
            foreach (array_unique($ramais) as $r) {
                $conta[$r] = ($conta[$r] ?? 0) + 1;
            }
        }
        arsort($conta);
        $conta = array_slice($conta, 0, 6, true);
        if ($conta === []) {
            return [];
        }
        $nomes = array_column(Bd::todos(
            'SELECT numero, nome FROM ramais WHERE numero IN (' . implode(',', array_fill(0, count($conta), '?')) . ')',
            array_map('strval', array_keys($conta))
        ), 'nome', 'numero');

        $saida = [];
        foreach ($conta as $ramal => $qtd) {
            $saida[] = ['ramal' => (string) $ramal, 'nome' => $nomes[$ramal] ?? null, 'qtd' => $qtd];
        }

        return $saida;
    }

    private function troncos(): array
    {
        $troncos = Bd::todos('SELECT id, nome, tipo, host, canais_max, ativo FROM troncos ORDER BY nome');
        if ($troncos === []) {
            return [];
        }

        $estado = $this->estadoDosTroncos();

        return array_map(static function (array $t) use ($estado): array {
            $chave = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $t['nome']);
            $t['estado'] = $estado[$chave] ?? ((int) $t['ativo'] === 1 ? 'desconhecido' : 'inativo');
            return $t;
        }, $troncos);
    }

    private function atividades(): array
    {
        return Bd::todos(
            'SELECT usuario_nome, acao, modulo, objeto, ip,
                    DATE_FORMAT(criado_em, "%H:%i") AS hora
               FROM auditoria
           ORDER BY id DESC
              LIMIT 8'
        );
    }

    // ------------------------------------------------------------------
    /** @return array{chamadas:int, canais:int, ok:bool} */
    private function canaisAtivos(): array
    {
        $saida = $this->ami('core show channels');
        if ($saida === null) {
            return ['chamadas' => 0, 'canais' => 0, 'ok' => false];
        }

        preg_match('/(\d+)\s+active channel/i', $saida, $c);
        // Com maxcalls no asterisk.conf (o padrão da instalação) a linha é
        // "2 of 480 max active calls", e o \d+ colado no "active" lia 0.
        preg_match('/(\d+)(?:\s+of\s+\d+\s+max)?\s+active call/i', $saida, $l);

        // O Asterisk conta uma "call" por canal rodando dialplan, e o Local
        // com que a fila chama o agente (telium-agente, ou o de confirmação)
        // roda: uma ligação de fila atendida aparecia como duas. O lado ;2
        // desses Locais não é ligação nova.
        $locais = preg_match_all('#^(?:Output: )?Local/[^@!]+@telium-(?:agente|confirma-[0-9]+)-[0-9a-f]+;2!#m',
                                 (string) $this->ami('core show channels concise'));

        return ['chamadas' => max(0, (int) ($l[1] ?? 0) - (int) $locais), 'canais' => (int) ($c[1] ?? 0), 'ok' => true];
    }

    /** @return array<string,string> nome do endpoint => estado */
    private function estadoDosTroncos(): array
    {
        $saida = $this->ami('pjsip show endpoints');
        if ($saida === null) {
            return [];
        }

        $estados = [];
        // Sem o "Output: " do AMI, e com o estado lido até os dois espaços
        // seguintes: "Not in use" tem espaço, e \S+ pegava só "Not".
        foreach (explode("\n", Diagnostico::semEnvelope($saida)) as $linha) {
            if (preg_match('/^\s*Endpoint:\s+([^\s\/<]+)\S*\s{2,}(\S+(?: \S+)*)\s{2,}/', $linha, $m) === 1) {
                $estados[$m[1]] = match (strtolower($m[2])) {
                    'not in use', 'in use' => 'registrado',
                    'unavailable' => 'offline',
                    default => strtolower($m[2]),
                };
            }
        }

        return $estados;
    }

    private function ami(string $comando): ?string
    {
        return Ami::tentarComando($comando);
    }

    private function duracao(int $segundos): string
    {
        return $segundos >= 60
            ? sprintf('%dm %02ds', intdiv($segundos, 60), $segundos % 60)
            : $segundos . 's';
    }
}
