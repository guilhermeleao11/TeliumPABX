<?php
declare(strict_types=1);

namespace Telium\Servico;

use Telium\Dominio\CallCenter;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Sessao;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;

/**
 * O serviço de tempo real do call center (telium-cc).
 *
 * Escuta os eventos de fila do Asterisk e empurra o estado para os
 * painéis do agente e do supervisor, no instante em que muda. Até aqui
 * a central era "consultada sob demanda, não escutada": a tela só sabia
 * de uma pausa quando alguém recarregava.
 *
 * O estado que ele empurra não é dele: a cada mudança pergunta ao
 * Asterisk (QueueStatus) e repassa. Não há estado próprio para ficar
 * errado — se o serviço cair e voltar, a primeira foto já é a certa.
 *
 * Além de repassar, faz o que só quem escuta pode fazer:
 *  - registra cada atendimento (cc_atendimentos) quando o agente atende;
 *  - pausa em "Pós-atendimento" quem precisa tabular;
 *  - atende os códigos do telefone (*40, *42, *44, *49), que chegam
 *    pelo dialplan em /interno/…;
 *  - disca os retornos pedidos na espera quando há agente livre.
 *
 * Um processo, um laço de stream_select: AMI de eventos, AMI de ações,
 * o socket HTTP em 127.0.0.1 e os navegadores conectados. O nginx põe
 * /api/cc/eventos na frente, e só ele fala com a internet.
 */
final class TempoReal
{
    private const INTERVALO_ENVIO = 0.7;   // no máximo uma foto a cada 0,7 s
    private const INTERVALO_RESINC = 20.0; // foto nova mesmo sem evento
    private const INTERVALO_PULSO = 15.0;  // comentário SSE, para o proxy não fechar
    private const INTERVALO_HOJE = 15.0;   // números do dia, do banco
    private const INTERVALO_RETORNO = 5.0;

    /** @var resource|null */
    private $eventos = null;
    private string $bufEventos = '';
    private ?Ami $acoes = null;
    private float $proximaConexao = 0.0;

    /** @var resource */
    private $servidor;

    /**
     * @var array<int, array{sock: resource, entrada: string, saida: string,
     *                       fluxo: bool, supervisor: bool, agente: ?int}>
     */
    private array $clientes = [];

    private bool $sujo = true;
    private float $ultimoEnvio = 0.0;
    private float $ultimoPulso = 0.0;
    private float $ultimaResinc = 0.0;
    private float $ultimoHoje = 0.0;
    private float $ultimoRetorno = 0.0;

    /** @var array{filas: array<string,mixed>, agentes: array<int,mixed>} */
    private array $estado = ['filas' => [], 'agentes' => []];
    /** @var array<string,mixed> */
    private array $hoje = ['filas' => [], 'agentes' => []];
    /** @var array<string,array<string,mixed>> nome => motivo */
    private array $motivos = [];

    public function __construct(private readonly string $endereco = '127.0.0.1:8095')
    {
    }

    public function rodar(): void
    {
        $erro = 0;
        $msg = '';
        $servidor = @stream_socket_server("tcp://{$this->endereco}", $erro, $msg);
        if ($servidor === false) {
            throw new \RuntimeException("Não foi possível escutar em {$this->endereco}: {$msg}");
        }
        stream_set_blocking($servidor, false);
        $this->servidor = $servidor;
        $this->log("escutando em {$this->endereco}");

        while (true) {
            $this->garantirAmi();

            $ler = [$this->servidor];
            if ($this->eventos !== null) {
                $ler[] = $this->eventos;
            }
            foreach ($this->clientes as $c) {
                $ler[] = $c['sock'];
            }
            $escrever = null;
            $excecao = null;

            if (@stream_select($ler, $escrever, $excecao, 0, 250000) === false) {
                usleep(100000);
            }

            foreach ($ler as $s) {
                if ($s === $this->servidor) {
                    $this->aceitar();
                } elseif ($s === $this->eventos) {
                    $this->lerEventos();
                } else {
                    $this->lerCliente((int) $s);
                }
            }

            $this->tarefas();
        }
    }

    // ------------------------------------------------------------------
    // AMI
    // ------------------------------------------------------------------

    private function garantirAmi(): void
    {
        if ($this->eventos !== null && $this->acoes !== null) {
            return;
        }
        if (microtime(true) < $this->proximaConexao) {
            return;
        }

        try {
            $acoes = Ami::doAmbiente();
            $acoes->conectar();

            $s = @fsockopen(
                (string) \Telium\Suporte\Ambiente::get('AMI_HOST', '127.0.0.1'),
                \Telium\Suporte\Ambiente::int('AMI_PORT', 5038),
                $erro, $msg, 3
            );
            if ($s === false) {
                throw new \RuntimeException("AMI de eventos indisponível: {$msg}");
            }
            fgets($s, 1024);
            fwrite($s, "Action: Login\r\n"
                . 'Username: ' . \Telium\Suporte\Ambiente::get('AMI_USER') . "\r\n"
                . 'Secret: ' . \Telium\Suporte\Ambiente::get('AMI_PASS') . "\r\n"
                // Só o que interessa ao call center: filas e agentes.
                // "call" traria cada canal criado na central inteira.
                . "Events: agent\r\n\r\n");
            stream_set_blocking($s, false);

            $this->eventos = $s;
            $this->acoes = $acoes;
            $this->bufEventos = '';
            $this->sujo = true;
            $this->log('conectado ao AMI');
        } catch (\Throwable $e) {
            $this->eventos = null;
            $this->acoes = null;
            $this->proximaConexao = microtime(true) + 5;
            $this->log('AMI fora: ' . $e->getMessage() . ' — tento de novo em 5 s');
        }
    }

    private function perderAmi(string $motivo): void
    {
        $this->log("AMI caiu: {$motivo}");
        if (is_resource($this->eventos)) {
            @fclose($this->eventos);
        }
        $this->acoes?->desconectar();
        $this->eventos = null;
        $this->acoes = null;
        $this->proximaConexao = microtime(true) + 2;
        $this->estado = ['filas' => [], 'agentes' => []];
        $this->sujo = true;
    }

    private function lerEventos(): void
    {
        $dados = @fread($this->eventos, 65536);
        if ($dados === false || ($dados === '' && feof($this->eventos))) {
            $this->perderAmi('conexão encerrada');

            return;
        }
        $this->bufEventos .= $dados;

        while (($p = strpos($this->bufEventos, "\r\n\r\n")) !== false) {
            $pacote = substr($this->bufEventos, 0, $p);
            $this->bufEventos = substr($this->bufEventos, $p + 4);
            $ev = Ami::campos($pacote);
            if (isset($ev['Event'])) {
                $this->tratarEvento($ev);
            }
        }
    }

    /** @param array<string,string> $ev */
    private function tratarEvento(array $ev): void
    {
        switch ($ev['Event']) {
            case 'AgentConnect':
                $this->registrarAtendimento($ev);
                break;
            case 'AgentComplete':
                $this->encerrarAtendimento($ev);
                break;
        }

        if (str_starts_with($ev['Event'], 'Queue') || str_starts_with($ev['Event'], 'Agent')) {
            $this->sujo = true;
        }
    }

    /** @param array<string,string> $ev */
    private function registrarAtendimento(array $ev): void
    {
        $agente = CallCenter::agenteDoMembro((string) ($ev['MemberName'] ?? ''));
        if ($agente === null) {
            return;
        }

        $this->comBanco(fn () => Bd::executar(
            'INSERT INTO cc_atendimentos (callid, fila, agente_id, numero, nome, espera_seg, atendido_em)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE atendido_em = VALUES(atendido_em)',
            [
                (string) ($ev['Uniqueid'] ?? ''),
                (string) ($ev['Queue'] ?? ''),
                $agente,
                substr((string) ($ev['CallerIDNum'] ?? ''), 0, 40) ?: null,
                substr(self::nome((string) ($ev['CallerIDName'] ?? '')), 0, 80) ?: null,
                (int) ($ev['HoldTime'] ?? 0),
            ]
        ));
    }

    /** @param array<string,string> $ev */
    private function encerrarAtendimento(array $ev): void
    {
        $agente = CallCenter::agenteDoMembro((string) ($ev['MemberName'] ?? ''));
        if ($agente === null) {
            return;
        }

        $this->comBanco(fn () => Bd::executar(
            'UPDATE cc_atendimentos SET encerrado_em = NOW() WHERE callid = ? AND agente_id = ?',
            [(string) ($ev['Uniqueid'] ?? ''), $agente]
        ));

        // Tabulação obrigatória: o agente fica em "Pós-atendimento" até
        // dizer o que foi resolvido. Quem já estava pausado por outro
        // motivo continua no dele.
        $obrigatoria = $this->comBanco(fn () => (int) Bd::valor(
            'SELECT tabulacao_obrigatoria FROM filas WHERE numero = ? AND callcenter = 1',
            [(string) ($ev['Queue'] ?? '')]
        ));
        if ($obrigatoria !== 1 || $this->acoes === null) {
            return;
        }

        $ja = $this->estado['agentes'][$agente] ?? null;
        if ($ja !== null && $ja['pausado']) {
            return;
        }

        try {
            (new CallCenter($this->acoes))->pausar($agente, CallCenter::PAUSA_TABULACAO);
        } catch (\Throwable $e) {
            $this->log("não consegui pausar o agente {$agente} para tabular: {$e->getMessage()}");
        }
    }

    // ------------------------------------------------------------------
    // Tarefas periódicas
    // ------------------------------------------------------------------

    private function tarefas(): void
    {
        $agora = microtime(true);

        if ($agora - $this->ultimoHoje >= self::INTERVALO_HOJE) {
            $this->ultimoHoje = $agora;
            $this->hoje = $this->comBanco(fn () => self::numerosDeHoje()) ?? $this->hoje;
            $this->motivos = $this->comBanco(fn () => array_column(
                Bd::todos('SELECT nome, limite_minutos, produtiva, sistema FROM cc_pausas_motivos'),
                null, 'nome'
            )) ?? $this->motivos;
            $this->sujo = true;
        }

        if ($agora - $this->ultimaResinc >= self::INTERVALO_RESINC) {
            $this->sujo = true;
        }

        if ($this->sujo && $agora - $this->ultimoEnvio >= self::INTERVALO_ENVIO) {
            $this->atualizarEstado();
            $this->ultimoEnvio = $agora;
            $this->ultimaResinc = $agora;
            $this->sujo = false;
            $this->transmitir();
        }

        if ($agora - $this->ultimoPulso >= self::INTERVALO_PULSO) {
            $this->ultimoPulso = $agora;
            foreach ($this->clientes as $id => $c) {
                if ($c['fluxo']) {
                    $this->enviar($id, ": pulso\n\n");
                }
            }
        }

        if ($agora - $this->ultimoRetorno >= self::INTERVALO_RETORNO && $this->acoes !== null) {
            $this->ultimoRetorno = $agora;
            try {
                (new Retornos($this->acoes))->despachar($this->estado);
            } catch (\Throwable $e) {
                $this->log('retornos: ' . $e->getMessage());
                $this->descartarBancoSePreciso($e);
            }
        }

        foreach ($this->clientes as $id => $c) {
            if ($c['saida'] !== '') {
                $this->enviar($id, '');
            }
        }
    }

    private function atualizarEstado(): void
    {
        if ($this->acoes === null) {
            $this->estado = ['filas' => [], 'agentes' => []];

            return;
        }

        try {
            $this->estado = (new CallCenter($this->acoes))->estado();
        } catch (\PDOException $e) {
            $this->descartarBancoSePreciso($e);
        } catch (\Throwable $e) {
            $this->perderAmi($e->getMessage());
        }
    }

    /**
     * Os números do dia, do queue_log: por fila e por agente.
     *
     * @return array{filas: array<string,array<string,int>>, agentes: array<int,array<string,int>>}
     */
    public static function numerosDeHoje(): array
    {
        $filas = [];
        foreach (Bd::todos(
            "SELECT q.queuename AS fila,
                    SUM(q.event = 'ENTERQUEUE') AS recebidas,
                    SUM(q.event = 'CONNECT') AS atendidas,
                    SUM(q.event = 'ABANDON') AS abandonadas,
                    SUM(q.event = 'CONNECT' AND CAST(q.data1 AS UNSIGNED) <= f.sla_segundos) AS no_sla
               FROM queue_log q JOIN filas f ON f.numero = q.queuename
              WHERE q.time >= CURDATE() AND f.callcenter = 1
           GROUP BY q.queuename"
        ) as $f) {
            $filas[(string) $f['fila']] = array_map('intval', array_diff_key($f, ['fila' => 1]));
        }

        $agentes = [];
        foreach (Bd::todos(
            "SELECT agent,
                    SUM(event = 'CONNECT') AS atendidas,
                    SUM(event IN ('COMPLETEAGENT','COMPLETECALLER')) AS encerradas,
                    SUM(IF(event IN ('COMPLETEAGENT','COMPLETECALLER'), CAST(data2 AS UNSIGNED), 0)) AS falado,
                    SUM(event = 'RINGNOANSWER') AS nao_atendeu
               FROM queue_log
              WHERE time >= CURDATE() AND agent LIKE 'Agente/%'
           GROUP BY agent"
        ) as $a) {
            $id = CallCenter::agenteDoMembro((string) $a['agent']);
            if ($id !== null) {
                $agentes[$id] = [
                    'atendidas'   => (int) $a['atendidas'],
                    'tma'         => (int) $a['encerradas'] > 0 ? intdiv((int) $a['falado'], (int) $a['encerradas']) : 0,
                    'nao_atendeu' => (int) $a['nao_atendeu'],
                ];
            }
        }

        return ['filas' => $filas, 'agentes' => $agentes];
    }

    /**
     * A foto que cada painel recebe. O supervisor vê tudo; o agente, a
     * si mesmo e o tamanho das filas dele — não o painel dos colegas.
     *
     * @return array<string,mixed>
     */
    public static function visao(array $estado, array $hoje, array $motivos, bool $supervisor, ?int $agente): array
    {
        $agora = time();

        $filas = [];
        foreach ($estado['filas'] as $numero => $f) {
            $espera = array_column($f['esperando'], 'espera');
            $resumo = [
                'numero'       => $numero,
                'nome'         => $f['nome'],
                'sla_segundos' => $f['sla_segundos'],
                'aguardando'   => count($f['esperando']),
                'maior_espera' => $espera === [] ? 0 : max($espera),
                'hoje'         => $hoje['filas'][$numero] ?? ['recebidas' => 0, 'atendidas' => 0, 'abandonadas' => 0, 'no_sla' => 0],
            ];
            if ($supervisor) {
                $resumo['esperando'] = $f['esperando'];
            }
            $filas[$numero] = $resumo;
        }

        $agentes = [];
        foreach ($estado['agentes'] as $id => $a) {
            if (!$supervisor && $id !== $agente) {
                continue;
            }
            $a['hoje'] = $hoje['agentes'][$id] ?? ['atendidas' => 0, 'tma' => 0, 'nao_atendeu' => 0];
            $limite = $a['pausado'] ? ($motivos[$a['motivo']]['limite_minutos'] ?? null) : null;
            $a['pausa_limite'] = $limite !== null ? (int) $limite * 60 : null;
            $agentes[] = $a;
        }

        if (!$supervisor && $agente !== null) {
            // O agente só enxerga as filas em que está.
            $minhas = array_keys($estado['agentes'][$agente]['filas'] ?? []);
            if ($minhas === []) {
                $minhas = array_column(CallCenter::filasDoAgente($agente), 'numero');
            }
            $filas = array_intersect_key($filas, array_flip(array_map('strval', $minhas)));
        }

        return [
            'agora'   => $agora,
            'ao_vivo' => true,
            'filas'   => array_values($filas),
            'agentes' => $agentes,
        ];
    }

    private function transmitir(): void
    {
        foreach ($this->clientes as $id => $c) {
            if (!$c['fluxo']) {
                continue;
            }
            $visao = $this->comBanco(fn () => self::visao(
                $this->estado, $this->hoje, $this->motivos, $c['supervisor'], $c['agente']
            ));
            if ($visao === null) {
                continue;
            }
            $this->enviar($id, 'data: ' . json_encode($visao, JSON_UNESCAPED_UNICODE) . "\n\n");
        }
    }

    // ------------------------------------------------------------------
    // HTTP: navegadores (pelo nginx) e o dialplan (direto, 127.0.0.1)
    // ------------------------------------------------------------------

    private function aceitar(): void
    {
        $s = @stream_socket_accept($this->servidor, 0);
        if ($s === false) {
            return;
        }
        stream_set_blocking($s, false);
        $this->clientes[(int) $s] = [
            'sock' => $s, 'entrada' => '', 'saida' => '',
            'fluxo' => false, 'supervisor' => false, 'agente' => null,
        ];
    }

    private function lerCliente(int $id): void
    {
        $c = &$this->clientes[$id];
        $dados = @fread($c['sock'], 8192);
        if ($dados === false || ($dados === '' && feof($c['sock']))) {
            $this->fechar($id);

            return;
        }

        // Cliente de fluxo não manda mais nada; o que chegar é descartado.
        if ($c['fluxo']) {
            return;
        }

        $c['entrada'] .= $dados;
        if (strlen($c['entrada']) > 16384) {
            $this->responder($id, 431, 'pedido grande demais');

            return;
        }
        if (!str_contains($c['entrada'], "\r\n\r\n")) {
            return;
        }

        [$cabecalho] = explode("\r\n\r\n", $c['entrada'], 2);
        $linhas = explode("\r\n", $cabecalho);
        $partes = explode(' ', (string) array_shift($linhas));
        $metodo = $partes[0] ?? '';
        $alvo = $partes[1] ?? '/';
        $cabecalhos = [];
        foreach ($linhas as $l) {
            $p = strpos($l, ':');
            if ($p !== false) {
                $cabecalhos[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
            }
        }

        $caminho = (string) parse_url($alvo, PHP_URL_PATH);
        parse_str((string) parse_url($alvo, PHP_URL_QUERY), $q);

        if ($metodo === 'GET' && $caminho === '/eventos') {
            $this->abrirFluxo($id, $cabecalhos['authorization'] ?? '');

            return;
        }
        if ($metodo === 'GET' && str_starts_with($caminho, '/interno/')) {
            $this->interno($id, substr($caminho, 9), $q);

            return;
        }

        $this->responder($id, 404, 'nada aqui');
    }

    private function abrirFluxo(int $id, string $autorizacao): void
    {
        $token = preg_match('/^Bearer\s+(\S+)$/i', $autorizacao, $m) === 1 ? $m[1] : '';
        $usuario = $token === '' ? null : $this->comBanco(fn () => Sessao::usuarioDoToken($token));
        if ($usuario === null) {
            $this->responder($id, 401, 'sessão inválida');

            return;
        }

        $allow = $this->comBanco(fn () => Permissoes::doPerfil((int) $usuario['perfil_id'])['allow']) ?? [];
        $supervisor = Permissoes::podeModulo($allow, 'cc.supervisor');
        $agente = $this->comBanco(fn () => CallCenter::agenteDoUsuario((int) $usuario['id']));

        if (!$supervisor && ($agente === null || !Permissoes::podeModulo($allow, 'cc.agente'))) {
            $this->responder($id, 403, 'sem acesso ao call center');

            return;
        }

        $c = &$this->clientes[$id];
        $c['fluxo'] = true;
        $c['supervisor'] = $supervisor;
        $c['agente'] = $agente !== null ? (int) $agente['id'] : null;
        $c['entrada'] = '';

        $this->enviar($id, "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream; charset=utf-8\r\n"
            . "Cache-Control: no-store\r\n"
            . "X-Accel-Buffering: no\r\n"
            . "Connection: keep-alive\r\n\r\n"
            . "retry: 3000\n\n");

        // A primeira foto sai já, não no próximo evento.
        $visao = $this->comBanco(fn () => self::visao(
            $this->estado, $this->hoje, $this->motivos, $supervisor, $c['agente']
        ));
        if ($visao !== null) {
            $this->enviar($id, 'data: ' . json_encode($visao, JSON_UNESCAPED_UNICODE) . "\n\n");
        }
    }

    /**
     * Os códigos do telefone. Quem chama é o dialplan, por CURL, em
     * 127.0.0.1 — o nginx não publica este caminho. O segredo vai junto
     * mesmo assim: qualquer processo da máquina alcança 127.0.0.1.
     *
     * A resposta é uma palavra, que o dialplan transforma em áudio.
     *
     * @param array<string,mixed> $q
     */
    private function interno(int $id, string $acao, array $q): void
    {
        $segredo = (string) ($this->comBanco(fn () => Bd::valor(
            "SELECT valor FROM sistema WHERE chave = 'cc_segredo_interno'"
        )) ?? '');
        if ($segredo === '' || !hash_equals($segredo, (string) ($q['k'] ?? ''))) {
            $this->responder($id, 403, 'erro');

            return;
        }

        if ($this->acoes === null) {
            $this->responder($id, 200, 'fora');

            return;
        }

        $ramal = preg_replace('/\D/', '', (string) ($q['ramal'] ?? '')) ?? '';
        $cc = new CallCenter($this->acoes);

        try {
            $resposta = match ($acao) {
                'login'  => $this->loginPeloTelefone($cc, $ramal, (string) ($q['codigo'] ?? '')),
                'logout' => $cc->deslogar($this->agenteNoRamal($ramal)) > 0 ? 'ok' : 'naologado',
                'pausa'  => $this->pausaPeloTelefone($cc, $ramal, (int) ($q['motivo'] ?? 0)),
                'volta'  => (function () use ($cc, $ramal) { $cc->despausar($this->agenteNoRamal($ramal)); return 'ok'; })(),
                default  => 'erro',
            };
        } catch (\DomainException $e) {
            $resposta = str_contains($e->getMessage(), 'não está logado') ? 'naologado' : 'erro';
            $this->log("telefone {$ramal} {$acao}: {$e->getMessage()}");
        } catch (\Throwable $e) {
            $resposta = 'erro';
            $this->log("telefone {$ramal} {$acao}: {$e->getMessage()}");
            $this->descartarBancoSePreciso($e);
        }

        $this->sujo = true;
        $this->responder($id, 200, $resposta);
    }

    private function loginPeloTelefone(CallCenter $cc, string $ramal, string $codigo): string
    {
        $agente = CallCenter::agenteDoCodigo($codigo);
        if ($agente === null) {
            return 'pin';
        }
        if (CallCenter::filasDoAgente((int) $agente['id']) === []) {
            return 'semfila';
        }

        try {
            $cc->logar((int) $agente['id'], $ramal);
        } catch (\DomainException $e) {
            return str_contains($e->getMessage(), 'já está com') ? 'ocupado' : 'erro';
        }

        return 'ok';
    }

    private function pausaPeloTelefone(CallCenter $cc, string $ramal, int $codigo): string
    {
        $motivo = Bd::valor(
            'SELECT nome FROM cc_pausas_motivos WHERE codigo = ? AND ativo = 1 AND sistema = 0',
            [$codigo]
        );
        if (!$motivo) {
            return 'motivo';
        }
        $cc->pausar($this->agenteNoRamal($ramal), (string) $motivo);

        return 'ok';
    }

    private function agenteNoRamal(string $ramal): int
    {
        $this->atualizarEstado();
        foreach ($this->estado['agentes'] as $a) {
            if ($a['ramal'] === $ramal) {
                return (int) $a['id'];
            }
        }

        throw new \DomainException('Nenhum agente está logado neste ramal — não está logado.');
    }

    private function responder(int $id, int $codigo, string $corpo): void
    {
        $texto = [200 => 'OK', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
                  431 => 'Request Header Fields Too Large'][$codigo] ?? 'Error';
        $this->enviar($id, "HTTP/1.1 {$codigo} {$texto}\r\nContent-Type: text/plain; charset=utf-8\r\n"
            . 'Content-Length: ' . strlen($corpo) . "\r\nConnection: close\r\n\r\n{$corpo}");
        $this->clientes[$id]['fechar'] = true;
        if ($this->clientes[$id]['saida'] === '') {
            $this->fechar($id);
        }
    }

    /** Escreve sem travar: o que não coube fica para a próxima volta. */
    private function enviar(int $id, string $texto): void
    {
        if (!isset($this->clientes[$id])) {
            return;
        }
        $c = &$this->clientes[$id];
        $c['saida'] .= $texto;

        $n = @fwrite($c['sock'], $c['saida']);
        if ($n === false) {
            $this->fechar($id);

            return;
        }
        $c['saida'] = (string) substr($c['saida'], $n);

        // Navegador que parou de ler (aba suspensa, rede ruim) não pode
        // fazer o serviço acumular memória para sempre.
        if (strlen($c['saida']) > 1048576) {
            $this->fechar($id);

            return;
        }
        if ($c['saida'] === '' && !empty($c['fechar'])) {
            $this->fechar($id);
        }
    }

    private function fechar(int $id): void
    {
        if (isset($this->clientes[$id])) {
            @fclose($this->clientes[$id]['sock']);
            unset($this->clientes[$id]);
        }
    }

    // ------------------------------------------------------------------

    /**
     * Roda uma consulta; se a conexão com o banco caiu (servidor que
     * ficou dias de pé), reabre e tenta uma vez mais.
     *
     * @template T
     * @param callable(): T $f
     * @return T|null
     */
    private function comBanco(callable $f): mixed
    {
        for ($tentativa = 0; $tentativa < 2; $tentativa++) {
            try {
                return $f();
            } catch (\PDOException $e) {
                $this->log('banco: ' . $e->getMessage());
                Bd::desconectar();
            }
        }

        return null;
    }

    private function descartarBancoSePreciso(\Throwable $e): void
    {
        if ($e instanceof \PDOException) {
            Bd::desconectar();
        }
    }

    private static function nome(string $nome): string
    {
        return in_array(strtolower($nome), ['', 'unknown', '<unknown>'], true) ? '' : $nome;
    }

    private function log(string $msg): void
    {
        fwrite(STDERR, date('Y-m-d H:i:s') . " telium-cc: {$msg}\n");
    }
}
