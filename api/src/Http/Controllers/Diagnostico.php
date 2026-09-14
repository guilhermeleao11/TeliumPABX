<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * O que a central está fazendo agora, lido do Asterisk.
 *
 * São telas de apoio a quem dá suporte: em vez de pedir ao cliente que
 * abra um terminal e rode "pjsip show transports", a resposta já está
 * na tela. Nada aqui altera configuração.
 */
final class Diagnostico
{
    /** GET /api/diagnostico/rede — transportes, portas e NAT. */
    public function rede(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'transportes' => $this->tabela('pjsip show transports', [
                'id' => 1, 'tipo' => 2, 'endereco' => 5,
            ], '/^Transport:\s+(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\S+)/'),
            'rtp'   => $this->blocoCli('rtp show settings'),
            'portas' => [
                'sip'      => Ambiente::int('SIP_PORTA', 5060),
                'sip_tls'  => Ambiente::int('SIP_TLS_PORTA', 5061),
                'ws'       => Ambiente::int('AST_HTTP_PORTA', 8090),
                'ami'      => Ambiente::int('AMI_PORT', 5038),
            ],
            'http'  => $this->blocoCli('http show status'),
            'ami'   => Ami::tentarComando('core show version') !== null,
        ]);
    }

    /**
     * GET /api/diagnostico/webrtc — o caminho inteiro do softphone.
     *
     * A chamada do navegador depende de quatro coisas alinhadas:
     * o transporte wss, o WebSocket publicado pelo Asterisk, o ICE
     * configurado e o ramal marcado como WebRTC. Quando falha, é uma
     * dessas — e olhar as quatro juntas é o que economiza a tarde.
     */
    public function webrtc(Request $req, Response $res): Response
    {
        $transportes = (string) (Ami::tentarComando('pjsip show transports') ?? '');
        $http = (string) (Ami::tentarComando('http show status') ?? '');

        $ramais = Bd::todos(
            'SELECT numero, nome, webrtc, ativo, dtls, avpf, ice, rtcp_mux, transporte
               FROM ramais WHERE ativo = 1 ORDER BY numero'
        );

        $contatos = (string) (Ami::tentarComando('pjsip show contacts') ?? '');
        foreach ($ramais as &$r) {
            $r['registrado'] = str_contains($contatos, "{$r['numero']}/sip:");
        }
        unset($r);

        $turn = trim((string) Ambiente::get('SOFTPHONE_TURN', ''));

        return Resposta::json($res, [
            'transporte_wss' => str_contains($transportes, 'transport-wss'),
            'websocket'      => str_contains($http, '/ws'),
            'http_ligado'    => str_contains($http, 'Server Enabled'),
            'stun'           => trim((string) Ambiente::get('SOFTPHONE_STUN', '')),
            'turn'           => $turn === '' ? [] : array_map('trim', explode(',', $turn)),
            'turn_proprio'   => trim((string) Ambiente::get('TURN_SEGREDO', '')) !== '',
            'ramais'         => array_values(array_filter(
                $ramais,
                static fn (array $r): bool => (int) $r['webrtc'] === 1
            )),
            'ramais_total'   => count($ramais),
        ]);
    }

    /**
     * GET /api/diagnostico/seguranca — quem está tentando entrar.
     *
     * O Asterisk registra cada falha de autenticação no canal de
     * segurança. É o primeiro lugar onde uma varredura de ramal
     * aparece, dias antes de virar conta de telefone.
     */
    public function seguranca(Request $req, Response $res): Response
    {
        $eventos = [];
        $arquivo = '/var/log/asterisk/security';

        if (is_readable($arquivo)) {
            // Só o fim do arquivo: ele cresce por semanas e a pergunta
            // é sempre sobre agora.
            foreach ($this->ultimasLinhas($arquivo, 4000) as $linha) {
                if (preg_match('/SecurityEvent="([^"]+)"/', $linha, $m) !== 1) {
                    continue;
                }
                $tipo = $m[1];
                if (in_array($tipo, ['SuccessfulAuth', 'ChallengeSent'], true)) {
                    continue;   // ruído: toda chamada boa gera os dois
                }

                preg_match('/AccountID="([^"]*)"/', $linha, $conta);
                preg_match('/RemoteAddress="[^\/]*\/[^\/]*\/([^\/]*)\//', $linha, $ip);
                preg_match('/^\[([^\]]+)\]/', $linha, $quando);

                $eventos[] = [
                    'quando' => $quando[1] ?? '',
                    'tipo'   => $tipo,
                    'conta'  => $conta[1] ?? '',
                    'ip'     => $ip[1] ?? '',
                ];
            }
        }

        $eventos = array_slice(array_reverse($eventos), 0, 200);

        // Contagem por origem: um IP com dezenas de falhas é varredura,
        // não usuário errando a senha.
        $porIp = [];
        foreach ($eventos as $e) {
            if ($e['ip'] === '') {
                continue;
            }
            $porIp[$e['ip']] ??= ['ip' => $e['ip'], 'tentativas' => 0, 'ultima' => $e['quando']];
            $porIp[$e['ip']]['tentativas']++;
        }
        usort($porIp, static fn (array $a, array $b): int => $b['tentativas'] <=> $a['tentativas']);

        return Resposta::json($res, [
            'disponivel' => is_readable($arquivo),
            'arquivo'    => $arquivo,
            'eventos'    => $eventos,
            'origens'    => array_slice($porIp, 0, 20),
            // "acl show" e não "pjsip show acls": o segundo não existe
            // no Asterisk 22 e a tela mostrava o erro do CLI ao cliente.
            'acl'        => $this->blocoCli('acl show'),
            'login_web'  => Bd::todos(
                "SELECT ip, COUNT(*) AS tentativas, MAX(criado_em) AS ultima
                   FROM auditoria
                  WHERE acao = 'login_falha' AND criado_em > DATE_SUB(NOW(), INTERVAL 7 DAY)
               GROUP BY ip ORDER BY tentativas DESC LIMIT 20"
            ),
        ]);
    }

    /** GET /api/diagnostico/sip — como o PJSIP está configurado agora. */
    public function sip(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'globais'     => $this->blocoCli('pjsip show settings'),
            'transportes' => $this->blocoCli('pjsip show transports'),
            'endpoints'   => $this->blocoCli('pjsip show endpoints'),
            'registros'   => $this->blocoCli('pjsip show registrations'),
            'codecs'      => $this->blocoCli('core show translation'),
        ]);
    }

    /**
     * GET /api/diagnostico/dids — todos os números que chegam à central.
     *
     * Ficam em dois lugares: nas rotas de entrada e no DID do próprio
     * ramal. Ver os dois juntos é o que evita cadastrar duas vezes o
     * mesmo número e descobrir depois qual das duas venceu.
     */
    public function dids(Request $req, Response $res): Response
    {
        $rotas = Bd::todos(
            'SELECT did, descricao, destino_tipo, destino_valor, gravar, ativo, ordem
               FROM rotas_entrada ORDER BY ordem, did'
        );

        $doRamal = Bd::todos(
            "SELECT did, did_descricao AS descricao, numero, nome, ativo
               FROM ramais
              WHERE ativo = 1 AND did IS NOT NULL AND did <> ''
           ORDER BY did"
        );

        $listados = array_column($rotas, 'did');
        $lista = [];

        foreach ($rotas as $r) {
            $lista[] = [
                'did'       => $r['did'],
                'descricao' => $r['descricao'],
                'origem'    => 'rota',
                'destino'   => "{$r['destino_tipo']} {$r['destino_valor']}",
                'gravar'    => (int) $r['gravar'],
                'ativo'     => (int) $r['ativo'],
                'conflito'  => false,
            ];
        }

        foreach ($doRamal as $r) {
            $conflito = in_array($r['did'], $listados, true);
            $lista[] = [
                'did'       => $r['did'],
                'descricao' => $r['descricao'] ?: "Ramal {$r['numero']}",
                'origem'    => 'ramal',
                'destino'   => "ramal {$r['numero']} — {$r['nome']}",
                'gravar'    => 0,
                'ativo'     => (int) $r['ativo'],
                // A rota explícita vence; o DID do ramal fica sem efeito.
                'conflito'  => $conflito,
            ];
        }

        return Resposta::json($res, ['dados' => $lista]);
    }

    // ---------------------------------------------------------------
    /** Saída bruta de um comando de CLI, já sem o envelope do AMI. */
    private function blocoCli(string $comando): array
    {
        $bruto = Ami::tentarComando($comando);
        if ($bruto === null) {
            return ['disponivel' => false, 'texto' => ''];
        }

        return ['disponivel' => true, 'texto' => $this->semEnvelope($bruto)];
    }

    /**
     * Tira o envelope do AMI.
     *
     * A resposta de um comando vem com o cabeçalho da ação e cada linha
     * do CLI prefixada por "Output: " — sem limpar, a tela mostra isso
     * ao cliente.
     */
    private function semEnvelope(string $bruto): string
    {
        $linhas = [];
        foreach (explode("\n", $bruto) as $linha) {
            $linha = rtrim($linha, "\r");
            if (preg_match('/^(Response|Message|Privilege|ActionID|Event|--END COMMAND--)/', $linha)) {
                continue;
            }
            $linhas[] = preg_replace('/^Output:\s?/', '', $linha) ?? $linha;
        }

        return trim(implode("\n", $linhas));
    }

    /**
     * Uma tabela do CLI virando lista.
     *
     * @param array<string,int> $campos nome => número do grupo do regex
     * @return list<array<string,string>>
     */
    private function tabela(string $comando, array $campos, string $regex): array
    {
        $bruto = Ami::tentarComando($comando);
        if ($bruto === null) {
            return [];
        }

        $linhas = [];
        foreach (explode("\n", $this->semEnvelope($bruto)) as $linha) {
            if (preg_match($regex, $linha, $m) !== 1) {
                continue;
            }
            if (str_contains($m[1], '<')) {
                continue;    // cabeçalho da tabela
            }
            $item = [];
            foreach ($campos as $nome => $grupo) {
                $item[$nome] = $m[$grupo] ?? '';
            }
            $linhas[] = $item;
        }

        return $linhas;
    }

    /** @return list<string> */
    private function ultimasLinhas(string $arquivo, int $quantas): array
    {
        $conteudo = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($conteudo === false) {
            return [];
        }

        return array_slice($conteudo, -$quantas);
    }
}
