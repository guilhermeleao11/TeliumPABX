<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Rede;
use Telium\Dominio\Stun;
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
            'nat' => [
                'ip_publico'    => Rede::ipPublico(),
                'redes_locais'  => implode(', ', Rede::redesLocais()),
                'tem_stun'      => Rede::servidoresStun() !== [],
            ],
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

    /**
     * GET /api/diagnostico/troncos — o que o Asterisk sabe de cada um.
     *
     * "Cadastrei o tronco e ele não aparece no Asterisk" é a dúvida mais
     * comum de quem instala uma central, e a tela não ajudava: a coluna
     * "Ativo" dizia apenas que a linha do banco está ativa. Aqui a
     * resposta vem da própria central.
     */
    public function troncos(Request $req, Response $res): Response
    {
        $cadastrados = Bd::todos(
            "SELECT nome, host, porta, registrar, ativo, tipo,
                    usuario <> '' AS tem_usuario, senha <> '' AS tem_senha
               FROM troncos ORDER BY nome"
        );

        $endpoints = (string) (Ami::tentarComando('pjsip show endpoints') ?? '');
        $registros = (string) (Ami::tentarComando('pjsip show registrations') ?? '');
        $contatos  = (string) (Ami::tentarComando('pjsip show contacts') ?? '');
        $auths     = (string) (Ami::tentarComando('pjsip show auths') ?? '');
        $central = $endpoints !== '';

        foreach ($cadastrados as &$t) {
            // O mesmo saneamento do gerador: o nome do objeto no Asterisk
            // não tem espaço nem acento.
            $id = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $t['nome']) ?? '';
            $t['id_asterisk'] = $id;

            $t['publicado'] = $id !== '' && preg_match(
                '/^\s*Endpoint:\s+' . preg_quote($id, '/') . '[\/\s]/mi',
                self::semEnvelope($endpoints)
            ) === 1;

            // O cadastro tem a credencial, mas a central carregou o
            // objeto de autenticação? Já aconteceu de o "aplicar"
            // responder sucesso, o arquivo em disco ficar certo e o
            // Asterisk seguir com a configuração anterior — e o único
            // sintoma era a operadora recusando o registro, que manda
            // conferir usuário e senha que estão corretos.
            $t['autenticado'] = (int) $t['tem_senha'] === 1 && self::temAuth($auths, $id);

            $t['estado_registro'] = self::estadoDoRegistro($registros, $id);
            $t['registrado'] = (int) $t['registrar'] === 1
                && $t['estado_registro'] === 'Registered';

            $t['estado_contato'] = self::estadoDoContato($contatos, $id);
            $t['respondendo'] = $t['estado_contato'] === 'Avail';

            // Tronco com qualify desligado nunca fica "Avail", e chamar
            // isso de fora do ar assustava sem motivo.
            $t['no_ar'] = $central
                && $t['tipo'] === 'pjsip'
                && (int) $t['ativo'] === 1
                && $t['publicado']
                && ((int) $t['tem_usuario'] !== 1 || (int) $t['tem_senha'] === 1)
                && ((int) $t['tem_senha'] !== 1 || $t['autenticado'])
                && ((int) $t['registrar'] !== 1 || $t['registrado'])
                && in_array($t['estado_contato'], ['Avail', 'NonQual'], true);

            // O diagnóstico em uma frase, que é o que se quer ler.
            $t['situacao'] = match (true) {
                !$central              => 'sem resposta da central',
                // Mandar aplicar não adiantaria: o gerador não escreve
                // tronco que não seja PJSIP.
                $t['tipo'] !== 'pjsip'  => "tipo \"{$t['tipo']}\" não é publicado por esta central",
                (int) $t['ativo'] !== 1 => 'desativado no cadastro',
                !$t['publicado']       => 'não publicado — aplique as configurações',
                // Sem senha o Asterisk recusa o auth e o registro nunca
                // sai. Acontecia sozinho: até esta versão, salvar o
                // tronco com o campo de senha em branco apagava a senha.
                (int) $t['tem_usuario'] === 1 && (int) $t['tem_senha'] !== 1
                    => 'usuário de autenticação sem senha — informe a senha da operadora',
                (int) $t['registrar'] === 1 && (int) $t['tem_usuario'] !== 1
                    => 'marcado para registrar, mas sem usuário e senha da operadora',
                (int) $t['tem_senha'] === 1 && !$t['autenticado']
                    => 'a central não carregou a autenticação deste tronco — aplique de novo',
                (int) $t['registrar'] === 1 && !$t['registrado'] => match ($t['estado_registro']) {
                    ''             => 'publicado, registro ainda não tentado',
                    'Rejected'     => 'operadora recusou o registro — confira usuário e senha',
                    'Unregistered' => 'publicado, operadora não registrou o tronco',
                    'Auth. Sent',
                    'Sent'         => 'registrando na operadora…',
                    default        => "registro na operadora: {$t['estado_registro']}",
                },
                $t['no_ar'] && $t['estado_contato'] === 'NonQual'
                                       => 'no ar (sem teste de resposta)',
                $t['no_ar']            => 'no ar',
                default                => match ($t['estado_contato']) {
                    ''        => 'publicado, sem contato conhecido da operadora',
                    'Unavail' => 'publicado, operadora não respondeu ao teste',
                    default   => "publicado, contato {$t['estado_contato']}",
                },
            };
        }
        unset($t);

        return Resposta::json($res, [
            'central'   => $central,
            'troncos'   => $cadastrados,
            'registros' => $this->blocoCli('pjsip show registrations'),
        ]);
    }

    /**
     * PUT /api/diagnostico/rede — endereço público e faixas locais.
     *
     * Só grava e marca configuração pendente. Aplicar é o mesmo botão de
     * sempre, porque o que muda aqui é um arquivo gerado como outro
     * qualquer — external_signaling_address e local_net recarregam sem
     * reiniciar o Asterisk.
     */
    public function salvarRede(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $ip = trim((string) ($corpo['ip_publico'] ?? ''));
        $locais = trim((string) ($corpo['redes_locais'] ?? ''));

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return Resposta::erro(
                $res,
                'O endereço público precisa ser um IP — o Asterisk não resolve nome aqui.',
                422,
                ['campo' => 'ip_publico']
            );
        }

        foreach (Rede::faixas($locais) as $faixa) {
            if (!Rede::faixaValida($faixa)) {
                return Resposta::erro(
                    $res,
                    "\"{$faixa}\" não é uma faixa de rede válida. Use algo como 192.168.0.0/16.",
                    422,
                    ['campo' => 'redes_locais']
                );
            }
        }

        Rede::guardar($ip, implode(',', Rede::faixas($locais)));
        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );

        return Resposta::json($res, [
            'ip_publico'   => Rede::ipPublico(),
            'redes_locais' => implode(', ', Rede::redesLocais()),
        ]);
    }

    /**
     * GET /api/diagnostico/rede/descobrir — pergunta o IP público a um STUN.
     *
     * Ninguém sabe de cor o IP de saída do próprio link, e errar esse
     * campo é pior do que deixá-lo vazio. Quem responde é o mesmo
     * servidor STUN que o softphone do navegador já usa.
     */
    public function descobrirIp(Request $req, Response $res): Response
    {
        $servidores = Rede::servidoresStun();
        if ($servidores === []) {
            return Resposta::json($res, [
                'ip' => null,
                'motivo' => 'Nenhum servidor STUN configurado nesta instalação.',
            ]);
        }

        $ip = Stun::primeiroQueResponder($servidores);

        return Resposta::json($res, [
            'ip' => $ip,
            'motivo' => $ip === null
                ? 'Nenhum servidor STUN respondeu. Confira se a saída UDP está liberada.'
                : null,
        ]);
    }

    /**
     * Estado da linha do tronco em "pjsip show registrations".
     *
     * A saída é tabular e as colunas variam — o Auth some quando o
     * tronco não autentica —, então lê-se a linha inteira e procura-se
     * a coluna que é um estado conhecido. Procurar "Registered" por
     * substring não serve: "Unregistered" também contém.
     */
    public static function estadoDoRegistro(string $saida, string $id): string
    {
        $achado = [];
        if ($id === '' || preg_match(
            '/^\s*' . preg_quote($id, '/') . '-reg\/\S+(.*)$/mi',
            self::semEnvelope($saida),
            $achado
        ) !== 1) {
            return '';
        }

        $conhecidos = ['Registered', 'Unregistered', 'Rejected', 'Auth. Sent', 'Sent', 'No Registration'];
        foreach (preg_split('/\s{2,}/', trim($achado[1])) ?: [] as $coluna) {
            if (in_array($coluna, $conhecidos, true)) {
                return $coluna;
            }
        }

        return 'Desconhecido';
    }

    /**
     * A central carregou o objeto de autenticação deste tronco?
     *
     * O nome do auth é o do tronco, e a coluna é "<auth>/<usuário>".
     * Casar só pelo começo confundiria "Magnus" com "Magnus-SPO", que é
     * exatamente o tipo de engano que faria a tela dizer "tudo certo"
     * sobre o tronco errado.
     */
    public static function temAuth(string $saida, string $id): bool
    {
        return $id !== '' && preg_match(
            '/^\s*Auth:\s+' . preg_quote($id, '/') . '\//mi',
            self::semEnvelope($saida)
        ) === 1;
    }

    /**
     * Estado do contato do tronco em "pjsip show contacts".
     *
     * Mesmo cuidado do registro: "Unavail" contém "Avail". A busca é
     * por palavra inteira, com maiúscula contando, e só na linha
     * daquele tronco.
     */
    public static function estadoDoContato(string $saida, string $id): string
    {
        $achado = [];
        if ($id === '' || preg_match(
            '/^\s*(?:Contact:\s*)?' . preg_quote($id, '/') . '\/sip:\S+(.*)$/mi',
            self::semEnvelope($saida),
            $achado
        ) !== 1) {
            return '';
        }

        foreach (['Avail', 'Unavail', 'Unknown', 'NonQual', 'Created', 'Removed', 'Updated'] as $estado) {
            if (preg_match('/\b' . $estado . '\b/', $achado[1]) === 1) {
                return $estado;
            }
        }

        return 'Desconhecido';
    }

    // ---------------------------------------------------------------
    /** Saída bruta de um comando de CLI, já sem o envelope do AMI. */
    private function blocoCli(string $comando): array
    {
        $bruto = Ami::tentarComando($comando);
        if ($bruto === null) {
            return ['disponivel' => false, 'texto' => ''];
        }

        return ['disponivel' => true, 'texto' => self::semEnvelope($bruto)];
    }

    /**
     * Tira o envelope do AMI.
     *
     * A resposta de um comando vem com o cabeçalho da ação e cada linha
     * do CLI prefixada por "Output: " — sem limpar, a tela mostra isso
     * ao cliente.
     */
    public static function semEnvelope(string $bruto): string
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
        foreach (explode("\n", self::semEnvelope($bruto)) as $linha) {
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
