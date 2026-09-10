<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
            'atividades' => $this->atividades(),
            'atualizado' => date('c'),
        ]);
    }

    // ------------------------------------------------------------------
    private function kpis(): array
    {
        $hoje = Bd::um(
            "SELECT
                COUNT(*) AS total,
                SUM(disposition = 'ANSWERED') AS atendidas,
                SUM(disposition IN ('NO ANSWER','BUSY','FAILED')) AS perdidas,
                AVG(NULLIF(billsec, 0)) AS tma
             FROM cdr WHERE DATE(calldate) = CURDATE()"
        ) ?? [];

        $ontem = Bd::um(
            "SELECT COUNT(*) AS total FROM cdr
              WHERE DATE(calldate) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
        ) ?? [];

        $total = (int) ($hoje['total'] ?? 0);
        $perdidas = (int) ($hoje['perdidas'] ?? 0);
        $totalOntem = (int) ($ontem['total'] ?? 0);
        $tma = (int) round((float) ($hoje['tma'] ?? 0));

        $variacao = $totalOntem > 0
            ? round((($total - $totalOntem) / $totalOntem) * 100, 1)
            : null;

        $ativas = $this->canaisAtivos();

        return [
            [
                'chave' => 'chamadas_hoje',
                'label' => 'Chamadas hoje',
                'valor' => number_format($total, 0, ',', '.'),
                'delta' => $variacao,
                'ico'   => 'phone',
                'tone'  => 'brand',
                'rodape' => $totalOntem > 0
                    ? 'vs. ' . number_format($totalOntem, 0, ',', '.') . ' ontem'
                    : 'sem histórico de ontem',
            ],
            [
                'chave' => 'em_atendimento',
                'label' => 'Em atendimento',
                'valor' => (string) $ativas['chamadas'],
                'delta' => null,
                'ico'   => 'headset',
                'tone'  => 'ok',
                'rodape' => $ativas['canais'] . ' canais ativos',
            ],
            [
                'chave' => 'abandono',
                'label' => 'Taxa de abandono',
                'valor' => $total > 0
                    ? number_format($perdidas / $total * 100, 1, ',', '.') . '%'
                    : '—',
                'delta' => null,
                'ico'   => 'phoneOff',
                'tone'  => 'warn',
                'rodape' => $total > 0 ? "{$perdidas} de {$total} chamadas" : 'sem chamadas hoje',
            ],
            [
                'chave' => 'tma',
                'label' => 'TMA — tempo médio',
                'valor' => $tma > 0 ? $this->duracao($tma) : '—',
                'delta' => null,
                'ico'   => 'clock',
                'tone'  => 'info',
                'rodape' => $tma > 0 ? 'chamadas atendidas hoje' : 'sem chamadas atendidas',
            ],
        ];
    }

    private function volumePorHora(): array
    {
        $linhas = Bd::todos(
            "SELECT HOUR(calldate) AS hora,
                    SUM(disposition = 'ANSWERED') AS atendidas,
                    SUM(disposition <> 'ANSWERED') AS perdidas
               FROM cdr
              WHERE DATE(calldate) = CURDATE()
           GROUP BY HOUR(calldate)
           ORDER BY hora"
        );

        if ($linhas === []) {
            return ['labels' => [], 'series' => [], 'vazio' => true];
        }

        $porHora = array_column($linhas, null, 'hora');
        $primeira = (int) min(array_keys($porHora));
        $ultima = (int) max(array_keys($porHora));

        $labels = [];
        $atendidas = [];
        $perdidas = [];
        for ($h = $primeira; $h <= $ultima; $h++) {
            $labels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h';
            $atendidas[] = (int) ($porHora[$h]['atendidas'] ?? 0);
            $perdidas[] = (int) ($porHora[$h]['perdidas'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => [
                ['key' => 'atendidas', 'label' => 'Atendidas', 'color' => 'var(--series-1)', 'values' => $atendidas],
                ['key' => 'perdidas',  'label' => 'Perdidas',  'color' => 'var(--series-2)', 'values' => $perdidas],
            ],
            'vazio' => false,
        ];
    }

    private function topRamais(): array
    {
        return Bd::todos(
            "SELECT c.src AS ramal, r.nome, COUNT(*) AS qtd
               FROM cdr c
          LEFT JOIN ramais r ON r.numero = c.src
              WHERE DATE(c.calldate) = CURDATE()
                AND c.disposition = 'ANSWERED'
                AND CHAR_LENGTH(c.src) <= 6
           GROUP BY c.src, r.nome
           ORDER BY qtd DESC
              LIMIT 6"
        );
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
    /** @return array{chamadas:int, canais:int} */
    private function canaisAtivos(): array
    {
        $saida = $this->ami('core show channels');
        if ($saida === null) {
            return ['chamadas' => 0, 'canais' => 0];
        }

        preg_match('/(\d+)\s+active channel/i', $saida, $c);
        preg_match('/(\d+)\s+active call/i', $saida, $l);

        return ['chamadas' => (int) ($l[1] ?? 0), 'canais' => (int) ($c[1] ?? 0)];
    }

    /** @return array<string,string> nome do endpoint => estado */
    private function estadoDosTroncos(): array
    {
        $saida = $this->ami('pjsip show endpoints');
        if ($saida === null) {
            return [];
        }

        $estados = [];
        foreach (explode("\n", $saida) as $linha) {
            if (preg_match('/^\s*Endpoint:\s+(\S+)\s+(\S+)/', $linha, $m) === 1) {
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
