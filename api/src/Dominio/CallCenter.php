<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Ami;
use Telium\Suporte\Bd;

/**
 * O call center: agentes que são pessoas, entrando no ramal em que estão.
 *
 * Quem está logado, pausado ou falando é o Asterisk que sabe — esta
 * classe pergunta a ele e manda nele, e não guarda estado próprio. Não
 * há tabela de "agente online" para ficar presa quando o Asterisk
 * reinicia ou alguém entra pelo telefone.
 *
 * Todo caminho passa por aqui: o console (API), o telefone (códigos
 * *40, *42, *44, *49, pelo serviço de tempo real) e o supervisor. Cada
 * um gera os mesmos eventos no Asterisk e as mesmas linhas no queue_log.
 *
 * O membro da fila leva o nome "Agente/<id>". É esse nome que o
 * Asterisk escreve no queue_log (log_membername_as_agent), e é o que
 * faz o relatório ser da pessoa, e não do ramal em que ela sentou.
 */
final class CallCenter
{
    public const PREFIXO = 'Agente/';

    /** Motivos que a própria central põe — o nome é o do queue_log. */
    public const PAUSA_TABULACAO = 'Pós-atendimento';
    public const PAUSA_NAO_ATENDEU = 'Não atendeu';

    /** Status de dispositivo do app_queue. */
    private const STATUS = [
        0 => 'desconhecido', 1 => 'livre', 2 => 'falando', 3 => 'ocupado',
        4 => 'invalido', 5 => 'indisponivel', 6 => 'tocando', 7 => 'tocando',
        8 => 'espera',
    ];

    public function __construct(private readonly Ami $ami)
    {
    }

    public static function compartilhado(): self
    {
        return new self(Ami::compartilhada());
    }

    public static function nomeDoMembro(int $agenteId): string
    {
        return self::PREFIXO . $agenteId;
    }

    /** O id do agente a partir do nome do membro, ou null se não é agente. */
    public static function agenteDoMembro(string $nome): ?int
    {
        return preg_match('#^' . preg_quote(self::PREFIXO, '#') . '(\d+)$#', $nome, $m) === 1
            ? (int) $m[1] : null;
    }

    // ------------------------------------------------------------------
    // Cadastro
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null agente com nome e usuário */
    public static function agente(int $id): ?array
    {
        return Bd::um(
            'SELECT a.*, u.nome, u.usuario, u.ramal AS ramal_usuario
               FROM cc_agentes a JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null o agente desta conta do console */
    public static function agenteDoUsuario(int $usuarioId): ?array
    {
        $id = Bd::valor('SELECT id FROM cc_agentes WHERE usuario_id = ?', [$usuarioId]);

        return $id ? self::agente((int) $id) : null;
    }

    /**
     * As filas do agente que estão de pé: ativas e marcadas como call center.
     *
     * @return list<array{numero:string, nome:string, penalidade:int}>
     */
    public static function filasDoAgente(int $agenteId): array
    {
        return array_map(static fn (array $f): array => [
            'numero'     => (string) $f['numero'],
            'nome'       => (string) $f['nome'],
            'penalidade' => (int) $f['penalidade'],
        ], Bd::todos(
            'SELECT f.numero, f.nome, af.penalidade
               FROM cc_agente_filas af JOIN filas f ON f.id = af.fila_id
              WHERE af.agente_id = ? AND f.ativo = 1 AND f.callcenter = 1
           ORDER BY f.numero',
            [$agenteId]
        ));
    }

    /** SHA-256 de sal + PIN, como o cadastro guarda. */
    public static function hashDoPin(string $sal, string $pin): string
    {
        return hash('sha256', $sal . $pin);
    }

    /** @return array{sal:string, hash:string} */
    public static function novoPin(string $pin): array
    {
        $sal = bin2hex(random_bytes(16));

        return ['sal' => $sal, 'hash' => self::hashDoPin($sal, $pin)];
    }

    /**
     * O agente dono do código digitado no telefone.
     *
     * O código é a matrícula; quem tem PIN cadastrado digita matrícula,
     * asterisco e PIN numa vez só ("501*1234"). Sem PIN no cadastro, só
     * a matrícula basta — e com PIN no cadastro, só a matrícula não basta.
     */
    public static function agenteDoCodigo(string $codigo): ?array
    {
        [$matricula, $pin] = array_pad(explode('*', $codigo, 2), 2, '');
        $matricula = preg_replace('/\D/', '', $matricula) ?? '';
        $pin = preg_replace('/\D/', '', $pin) ?? '';
        if ($matricula === '') {
            return null;
        }

        $a = Bd::um('SELECT id, pin_sal, pin_hash FROM cc_agentes WHERE matricula = ? AND ativo = 1', [$matricula]);
        if ($a === null) {
            return null;
        }
        if ($a['pin_hash'] === null || $a['pin_hash'] === '') {
            return self::agente((int) $a['id']);
        }

        return $pin !== '' && hash_equals((string) $a['pin_hash'], self::hashDoPin((string) $a['pin_sal'], $pin))
            ? self::agente((int) $a['id']) : null;
    }

    // ------------------------------------------------------------------
    // Estado — o que o Asterisk diz agora
    // ------------------------------------------------------------------

    /**
     * Filas de call center, membros e quem espera, como o Asterisk vê.
     *
     * @return array{filas: array<string,array<string,mixed>>, agentes: array<int,array<string,mixed>>}
     */
    public function estado(): array
    {
        $filasCc = [];
        foreach (Bd::todos('SELECT id, numero, nome, sla_segundos, tabulacao_obrigatoria
                              FROM filas WHERE ativo = 1 AND callcenter = 1') as $f) {
            $filasCc[(string) $f['numero']] = $f;
        }

        $filas = [];
        $membros = [];

        foreach ($this->ami->lista(['Action' => 'QueueStatus']) as $ev) {
            $fila = (string) ($ev['Queue'] ?? '');
            if (!isset($filasCc[$fila])) {
                continue;
            }

            switch ($ev['Event'] ?? '') {
                case 'QueueParams':
                    $filas[$fila] = [
                        'numero'       => $fila,
                        'nome'         => (string) $filasCc[$fila]['nome'],
                        'sla_segundos' => (int) $filasCc[$fila]['sla_segundos'],
                        'espera_media' => (int) ($ev['Holdtime'] ?? 0),
                        'fala_media'   => (int) ($ev['TalkTime'] ?? 0),
                        'atendidas'    => (int) ($ev['Completed'] ?? 0),
                        'abandonadas'  => (int) ($ev['Abandoned'] ?? 0),
                        'sla_perc'     => (float) ($ev['ServicelevelPerf'] ?? 0),
                        'esperando'    => [],
                    ];
                    break;

                case 'QueueEntry':
                    $filas[$fila]['esperando'][] = [
                        'posicao'  => (int) ($ev['Position'] ?? 0),
                        'numero'   => (string) ($ev['CallerIDNum'] ?? ''),
                        'nome'     => self::semDesconhecido((string) ($ev['CallerIDName'] ?? '')),
                        'espera'   => (int) ($ev['Wait'] ?? 0),
                        'callid'   => (string) ($ev['Uniqueid'] ?? ''),
                        'prioridade' => (int) ($ev['Priority'] ?? 0),
                    ];
                    break;

                case 'QueueMember':
                    $membros[] = $ev;
                    break;
            }
        }

        return ['filas' => $filas, 'agentes' => $this->agentesLogados($membros)];
    }

    /**
     * Agrupa os membros por agente: o mesmo agente aparece uma vez por fila.
     *
     * @param list<array<string,string>> $membros eventos QueueMember
     * @return array<int,array<string,mixed>>
     */
    public function agentesLogados(array $membros): array
    {
        $agentes = [];

        foreach ($membros as $m) {
            $id = self::agenteDoMembro((string) ($m['Name'] ?? $m['MemberName'] ?? ''));
            if ($id === null) {
                continue;
            }

            $a = $agentes[$id] ?? [
                'id'          => $id,
                'ramal'       => self::ramalDaInterface((string) ($m['Location'] ?? $m['Interface'] ?? '')),
                'filas'       => [],
                'pausado'     => false,
                'motivo'      => '',
                'pausa_desde' => null,
                'logado_desde' => null,
                'status'      => 'desconhecido',
                'em_chamada'  => false,
                'atendidas'   => 0,
                'ultima'      => null,
            ];

            $a['filas'][(string) $m['Queue']] = (int) ($m['Penalty'] ?? 0);
            $pausado = ($m['Paused'] ?? '0') === '1';
            $a['pausado'] = $a['pausado'] || $pausado;
            if ($pausado && ($m['PausedReason'] ?? '') !== '') {
                $a['motivo'] = (string) $m['PausedReason'];
            }
            if ($pausado && (int) ($m['LastPause'] ?? 0) > 0) {
                $a['pausa_desde'] = max((int) $a['pausa_desde'], (int) $m['LastPause']);
            }
            $login = (int) ($m['LoginTime'] ?? 0);
            if ($login > 0) {
                $a['logado_desde'] = $a['logado_desde'] === null ? $login : min($a['logado_desde'], $login);
            }
            $a['status'] = self::STATUS[(int) ($m['Status'] ?? 0)] ?? 'desconhecido';
            $a['em_chamada'] = $a['em_chamada'] || ($m['InCall'] ?? '0') === '1';
            $a['atendidas'] += (int) ($m['CallsTaken'] ?? 0);
            $ultima = (int) ($m['LastCall'] ?? 0);
            if ($ultima > 0) {
                $a['ultima'] = max((int) $a['ultima'], $ultima);
            }

            $agentes[$id] = $a;
        }

        if ($agentes !== []) {
            $marcas = implode(',', array_fill(0, count($agentes), '?'));
            foreach (Bd::todos(
                "SELECT a.id, a.matricula, u.nome FROM cc_agentes a JOIN usuarios u ON u.id = a.usuario_id
                  WHERE a.id IN ({$marcas})",
                array_keys($agentes)
            ) as $c) {
                $agentes[(int) $c['id']]['nome'] = (string) $c['nome'];
                $agentes[(int) $c['id']]['matricula'] = (string) $c['matricula'];
            }
        }

        return $agentes;
    }

    /** O ramal de "PJSIP/1001" (ou de um Local de confirmação). */
    public static function ramalDaInterface(string $interface): string
    {
        return preg_match('#^(?:PJSIP|SIP|Local)/([^@/;-]+)#', $interface, $m) === 1 ? $m[1] : '';
    }

    private static function semDesconhecido(string $nome): string
    {
        return in_array(strtolower($nome), ['', 'unknown', '<unknown>'], true) ? '' : $nome;
    }

    /** O agente está logado agora? Devolve o ramal, ou null. */
    public function ramalDoAgente(int $agenteId): ?string
    {
        $estado = $this->estado();

        return isset($estado['agentes'][$agenteId]) ? (string) $estado['agentes'][$agenteId]['ramal'] : null;
    }

    // ------------------------------------------------------------------
    // Comandos
    // ------------------------------------------------------------------

    /**
     * Põe o agente nas filas dele, no ramal dado.
     *
     * Um ramal, um agente: se outra pessoa já está logada nele, recusa —
     * duas pessoas na mesma mesa dividiriam as chamadas sem saber. Se a
     * própria pessoa está logada em outro ramal, sai de lá primeiro.
     *
     * @return array{ramal:string, filas:list<string>}
     */
    public function logar(int $agenteId, string $ramal): array
    {
        $agente = self::agente($agenteId);
        if ($agente === null || (int) $agente['ativo'] !== 1) {
            throw new \DomainException('Agente não encontrado ou desativado.');
        }

        $r = Bd::um('SELECT numero, estado_em_fila FROM ramais WHERE numero = ? AND ativo = 1', [$ramal]);
        if ($r === null) {
            throw new \DomainException("O ramal {$ramal} não existe ou está desativado.");
        }

        $filas = self::filasDoAgente($agenteId);
        if ($filas === []) {
            throw new \DomainException('Este agente não está em nenhuma fila de call center ativa.');
        }

        $estado = $this->estado();
        foreach ($estado['agentes'] as $outro) {
            if ($outro['ramal'] === $ramal && (int) $outro['id'] !== $agenteId) {
                throw new \DomainException(sprintf(
                    'O ramal %s já está com %s. Peça para sair antes, ou use outro ramal.',
                    $ramal, $outro['nome'] ?? ('o agente ' . $outro['id'])
                ));
            }
        }

        if (isset($estado['agentes'][$agenteId]) && $estado['agentes'][$agenteId]['ramal'] !== $ramal) {
            $this->deslogar($agenteId);
        }

        $interface = "PJSIP/{$ramal}";
        $entrou = [];
        foreach ($filas as $f) {
            $resposta = $this->ami->acao([
                'Action'         => 'QueueAdd',
                'Queue'          => $f['numero'],
                'Interface'      => $interface,
                'Penalty'        => (string) $f['penalidade'],
                'MemberName'     => self::nomeDoMembro($agenteId),
                'StateInterface' => $interface,
                'Paused'         => 'false',
            ]);

            // "Already there" não é erro: é quem já estava nesta fila.
            if (str_contains($resposta, 'Success') || stripos($resposta, 'already') !== false) {
                $entrou[] = $f['numero'];
            }
        }

        if ($entrou === []) {
            throw new \RuntimeException('O Asterisk recusou a entrada em todas as filas.');
        }

        return ['ramal' => $ramal, 'filas' => $entrou];
    }

    /** Tira o agente de todas as filas em que ele estiver. */
    public function deslogar(int $agenteId): int
    {
        $saiu = 0;
        foreach ($this->membrosDoAgente($agenteId) as $m) {
            $r = $this->ami->acao([
                'Action'    => 'QueueRemove',
                'Queue'     => (string) $m['Queue'],
                'Interface' => (string) $m['Location'],
            ]);
            $saiu += str_contains($r, 'Success') ? 1 : 0;
        }

        return $saiu;
    }

    /** Pausa em todas as filas, com o motivo escrito no queue_log. */
    public function pausar(int $agenteId, string $motivo): void
    {
        $interface = $this->interfaceDoAgente($agenteId);
        $r = $this->ami->acao([
            'Action'    => 'QueuePause',
            'Interface' => $interface,
            'Paused'    => 'true',
            'Reason'    => $motivo,
        ]);
        if (!str_contains($r, 'Success')) {
            throw new \RuntimeException('O Asterisk não aceitou a pausa.');
        }
    }

    public function despausar(int $agenteId): void
    {
        $interface = $this->interfaceDoAgente($agenteId);
        $r = $this->ami->acao([
            'Action'    => 'QueuePause',
            'Interface' => $interface,
            'Paused'    => 'false',
        ]);
        if (!str_contains($r, 'Success')) {
            throw new \RuntimeException('O Asterisk não aceitou a volta da pausa.');
        }
    }

    /**
     * Refaz as filas de um agente logado depois de o cadastro mudar:
     * entra nas novas, sai das que perdeu, ajusta o nível nas que ficaram.
     */
    public function sincronizar(int $agenteId): void
    {
        $membros = $this->membrosDoAgente($agenteId);
        if ($membros === []) {
            return;
        }

        $interface = (string) $membros[0]['Location'];
        $atuais = [];
        foreach ($membros as $m) {
            $atuais[(string) $m['Queue']] = (int) $m['Penalty'];
        }

        $desejadas = [];
        foreach (self::filasDoAgente($agenteId) as $f) {
            $desejadas[$f['numero']] = $f['penalidade'];
        }

        foreach (array_diff_key($atuais, $desejadas) as $fila => $_) {
            $this->ami->acao(['Action' => 'QueueRemove', 'Queue' => (string) $fila, 'Interface' => $interface]);
        }
        foreach ($desejadas as $fila => $penalidade) {
            if (!isset($atuais[$fila])) {
                $this->ami->acao([
                    'Action' => 'QueueAdd', 'Queue' => (string) $fila, 'Interface' => $interface,
                    'Penalty' => (string) $penalidade, 'MemberName' => self::nomeDoMembro($agenteId),
                    'StateInterface' => $interface,
                ]);
            } elseif ($atuais[$fila] !== $penalidade) {
                $this->ami->acao([
                    'Action' => 'QueuePenalty', 'Queue' => (string) $fila,
                    'Interface' => $interface, 'Penalty' => (string) $penalidade,
                ]);
            }
        }
    }

    /**
     * Escuta, sussurro ou intervenção: o ramal do supervisor toca e, ao
     * atender, entra na chamada do agente.
     *
     * q = sem bipe de aviso; E = sai quando o agente desliga;
     * w = sussurro (só o agente ouve); B = intervenção (os dois ouvem).
     */
    public function monitorar(int $agenteId, string $ramalSupervisor, string $modo): void
    {
        $opcoes = match ($modo) {
            'escutar'   => 'qE',
            'sussurrar' => 'qEw',
            'intervir'  => 'qEB',
            default     => throw new \DomainException('Modo de monitoria desconhecido.'),
        };

        $interface = $this->interfaceDoAgente($agenteId);
        $agente = self::agente($agenteId);

        $r = $this->ami->acao([
            'Action'      => 'Originate',
            'Channel'     => "PJSIP/{$ramalSupervisor}",
            'Application' => 'ChanSpy',
            'Data'        => "{$interface},{$opcoes}",
            'CallerID'    => sprintf('"%s: %s" <%s>', ucfirst($modo), $agente['nome'] ?? 'agente',
                                     self::ramalDaInterface($interface)),
            'Timeout'     => '30000',
            'Async'       => 'true',
        ]);
        if (!str_contains($r, 'Success')) {
            throw new \RuntimeException('O Asterisk não conseguiu chamar o seu ramal.');
        }
    }

    /** @return list<array<string,string>> os QueueMember deste agente */
    private function membrosDoAgente(int $agenteId): array
    {
        $nome = self::nomeDoMembro($agenteId);

        return array_values(array_filter(
            $this->ami->lista(['Action' => 'QueueStatus']),
            static fn (array $e): bool => ($e['Event'] ?? '') === 'QueueMember' && ($e['Name'] ?? '') === $nome
        ));
    }

    private function interfaceDoAgente(int $agenteId): string
    {
        $membros = $this->membrosDoAgente($agenteId);
        if ($membros === []) {
            throw new \DomainException('O agente não está logado.');
        }

        return (string) $membros[0]['Location'];
    }
}
