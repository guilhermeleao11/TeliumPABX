<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/** Saúde do servidor e estado do Asterisk — tudo medido, nada fixo. */
final class Sistema
{
    /** GET /api/sistema/estatisticas */
    public function estatisticas(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'cpu'      => $this->cpu(),
            'memoria'  => $this->memoria(),
            'disco'    => $this->disco(),
            'uptime'   => $this->uptime(),
            'kernel'   => trim((string) @php_uname('r')),
            'sistema'  => $this->distro(),
            'asterisk' => $this->asterisk(),
            'banco'    => (string) Bd::valor('SELECT VERSION()'),
            'php'      => PHP_VERSION,
        ]);
    }

    /** GET /api/sistema/asterisk — saída bruta de comandos do CLI */
    public function asteriskInfo(Request $req, Response $res): Response
    {
        $comandos = [
            'core show channels',
            'pjsip show endpoints',
            'pjsip show registrations',
            'queue show',
        ];

        $saidas = [];
        try {
            $ami = Ami::compartilhada();
            foreach ($comandos as $c) {
                $saidas[$c] = $this->limparResposta($ami->comando($c));
            }
        } catch (\Throwable $e) {
            return Resposta::erro($res, 'Asterisk indisponível: ' . $e->getMessage(), 503);
        }

        return Resposta::json($res, ['comandos' => $saidas]);
    }

    /** GET /api/tempo-real — wallboard */
    public function tempoReal(Request $req, Response $res): Response
    {
        $filas = Bd::todos(
            'SELECT f.numero, f.nome, f.estrategia, f.sla_segundos,
                    (SELECT COUNT(*) FROM fila_agentes a WHERE a.fila_id = f.id) AS agentes
               FROM filas f WHERE f.ativo = 1 ORDER BY f.numero'
        );

        $canais = [];
        $estadoFilas = [];
        $conectado = false;
        $erro = null;

        try {
            $ami = Ami::compartilhada();
            $canais = $this->canais($this->limparResposta($ami->comando('core show channels concise')));
            $estadoFilas = $this->filas($this->limparResposta($ami->comando('queue show')));
            $conectado = true;
        } catch (\Throwable $e) {
            // Asterisk fora do ar: devolvemos o cadastro sem os números ao vivo
            $erro = $e->getMessage();
        }

        foreach ($filas as &$f) {
            $vivo = $estadoFilas[$f['numero']] ?? null;
            $f['espera'] = $vivo['espera'] ?? 0;
            $f['online'] = $vivo['online'] ?? 0;
            $f['completadas'] = $vivo['completadas'] ?? 0;
            $f['abandonadas'] = $vivo['abandonadas'] ?? 0;
        }
        unset($f);

        // Central ociosa devolve listas vazias — isso não é o mesmo que
        // Asterisk fora do ar. O indicador reflete a conexão, não o volume.
        return Resposta::json($res, [
            'filas'         => $filas,
            'chamadas'      => $canais,
            'asterisk'      => $conectado,
            'asterisk_erro' => $erro,
        ]);
    }

    // ------------------------------------------------------------------
    private function cpu(): int
    {
        $carga = sys_getloadavg()[0] ?? 0.0;
        $nucleos = max(1, (int) shell_exec('nproc 2>/dev/null') ?: 1);

        return (int) min(100, round($carga / $nucleos * 100));
    }

    private function memoria(): array
    {
        $info = @file_get_contents('/proc/meminfo') ?: '';
        preg_match('/MemTotal:\s+(\d+)/', $info, $t);
        preg_match('/MemAvailable:\s+(\d+)/', $info, $d);

        $total = (int) ($t[1] ?? 0);
        $livre = (int) ($d[1] ?? 0);

        return [
            'total_mb' => (int) round($total / 1024),
            'usado_mb' => (int) round(($total - $livre) / 1024),
            'pct'      => $total > 0 ? (int) round(($total - $livre) / $total * 100) : 0,
        ];
    }

    private function disco(): array
    {
        $total = (float) @disk_total_space('/');
        $livre = (float) @disk_free_space('/');

        return [
            'total_gb' => round($total / 1073741824, 1),
            'usado_gb' => round(($total - $livre) / 1073741824, 1),
            'pct'      => $total > 0 ? (int) round(($total - $livre) / $total * 100) : 0,
        ];
    }

    private function uptime(): string
    {
        $s = (int) (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');
        $dias = intdiv($s, 86400);
        $horas = intdiv($s % 86400, 3600);
        $min = intdiv($s % 3600, 60);

        return $dias > 0
            ? sprintf('%d dias, %02d:%02d', $dias, $horas, $min)
            : sprintf('%02d:%02d', $horas, $min);
    }

    private function distro(): string
    {
        $os = @file_get_contents('/etc/os-release') ?: '';
        preg_match('/PRETTY_NAME="([^"]+)"/', $os, $m);

        return $m[1] ?? PHP_OS;
    }

    private function asterisk(): array
    {
        try {
            $ami = Ami::compartilhada();
            $versao = $this->limparResposta($ami->comando('core show version'));
            $canais = $this->limparResposta($ami->comando('core show channels'));

            preg_match('/Asterisk\s+(\S+)/', $versao, $v);
            preg_match('/(\d+)\s+active channel/i', $canais, $c);
            preg_match('/(\d+)\s+active call/i', $canais, $l);

            return [
                'ok' => true,
                'versao' => $v[1] ?? '?',
                'canais_ativos' => (int) ($c[1] ?? 0),
                'chamadas_ativas' => (int) ($l[1] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    /** Remove o envelope do AMI, deixando só a saída do comando. */
    private function limparResposta(string $bruto): string
    {
        $linhas = array_filter(
            explode("\n", $bruto),
            static fn (string $l): bool =>
                !preg_match('/^(Response|Message|Privilege|ActionID|--END COMMAND--)/', trim($l))
        );

        return trim(implode("\n", array_map('rtrim', $linhas)));
    }

    /** Interpreta "core show channels concise". */
    private function canais(string $saida): array
    {
        $lista = [];
        foreach (explode("\n", $saida) as $linha) {
            $c = explode('!', $linha);
            if (count($c) < 10 || $c[0] === '') {
                continue;
            }
            $lista[] = [
                'canal'    => $c[0],
                'contexto' => $c[1] ?? '',
                'exten'    => $c[2] ?? '',
                'estado'   => $c[4] ?? '',
                'aplicacao' => $c[5] ?? '',
                'cid'      => $c[7] ?? '',
                'duracao'  => (int) ($c[11] ?? 0),
            ];
        }
        return $lista;
    }

    /** Interpreta "queue show". */
    private function filas(string $saida): array
    {
        $filas = [];
        $atual = null;
        foreach (explode("\n", $saida) as $linha) {
            if (preg_match('/^(\S+)\s+has\s+(\d+)\s+calls.*?W:(\d+),\s*C:(\d+),\s*A:(\d+)/', $linha, $m) === 1) {
                $atual = $m[1];
                $filas[$atual] = [
                    'espera' => (int) $m[2],
                    'completadas' => (int) $m[4],
                    'abandonadas' => (int) $m[5],
                    'online' => 0,
                ];
                continue;
            }
            if ($atual !== null && preg_match('/\(ringinuse|Not in use|In use|Unavailable\)/i', $linha) === 1) {
                if (stripos($linha, 'Unavailable') === false) {
                    $filas[$atual]['online']++;
                }
            }
        }
        return $filas;
    }
}
