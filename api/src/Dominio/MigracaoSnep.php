<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Telium\Http\Controllers\Audios;
use Telium\Http\Controllers\CallCenter;
use Telium\Http\Controllers\Filas;
use Telium\Http\Controllers\Horarios;
use Telium\Http\Recurso;
use Telium\Suporte\Bd;

/**
 * Migração do SNEP 3: troncos, ramais, filas, agentes, regras de saída e
 * regras de entrada (com as URAs, anúncios e contextos que elas usam).
 *
 * Nada aqui grava direto no banco. Cada item do SNEP vira o mesmo pedido
 * que a tela do Telium faz, e passa pelo mesmo Recurso: validação, número
 * único, auditoria e "configuração pendente". A prévia roda tudo isso numa
 * transação desfeita no fim — o que ela mostra é o que a importação faz.
 *
 * O que já existe no Telium (ramal com o mesmo número, tronco ou fila com
 * o mesmo nome) é aproveitado e não é alterado: rodar a migração de novo
 * só cria o que falta.
 *
 * Áudios: os que vieram no pacote do SNEP são convertidos e cadastrados em
 * Áudios (na prévia, só cadastrados — o banco volta e nenhum arquivo
 * fica); os que já estão em Áudios com o mesmo nome são usados como estão.
 * Sem o arquivo, o relatório avisa: criar o anúncio apontando para o que
 * não existe deixaria o dialplan mudo — e o teste da atualização pararia
 * a central.
 */
final class MigracaoSnep
{
    // Sem g729: o Asterisk não o transcodifica, e um ramal ou tronco só com
    // ele derruba a chamada que chega em outro codec (veja a migração 21).
    private const CODECS = ['ulaw', 'alaw', 'gsm', 'g722', 'opus'];
    private const ESTRATEGIAS = ['ringall', 'rrmemory', 'leastrecent', 'fewestcalls', 'linear', 'random', 'wrandom'];
    private const DTMF = ['rfc2833' => 'rfc4733', 'rfc4733' => 'rfc4733', 'inband' => 'inband',
                          'info' => 'info', 'auto' => 'auto'];

    /** @var list<array<string,mixed>> */
    private array $linhas = [];
    /** @var list<array<string,string>> senhas SIP geradas (só na importação de verdade) */
    private array $senhas = [];
    /** @var array<string, array<string,mixed>> áudios que o SNEP usava, pelo nome no SNEP */
    private array $audios = [];
    /** @var array<string,int> contexto do SNEP => id do destino personalizado */
    private array $contextos = [];
    /** @var array<int,int> trunks.id do SNEP => troncos.id do Telium */
    private array $troncos = [];
    /** @var array<string,string> nome da fila no SNEP => número no Telium */
    private array $filas = [];
    /** @var array<string,int> nome da fila no SNEP => filas.id, só das criadas como call center */
    private array $filasCallCenter = [];
    /** @var array<string, list<array{agente:string, penalidade:int}>> agentes do SNEP por fila */
    private array $agentesDaFila = [];
    /** @var array<int, list<array{acao:string, cfg:array<string,string>}>> */
    private array $acoes = [];
    /** @var array<int, array{nome:string, expressoes:list<string>}> */
    private array $alias = [];
    /** @var array<string,string> fila do SNEP => número interno que uma regra levava a ela */
    private array $numeroDaFila = [];
    /** @var array<int, array{fila:string, numero:string}> regras que eram só o número interno de uma fila */
    private array $atalhos = [];
    /** @var array<string, array{destino:?array, avisos:list<string>}> destino já resolvido de cada regra e número */
    private array $destinosDasRegras = [];
    /** @var array<int,string> core_groups do SNEP: id => nome */
    private array $grupos = [];
    /** @var array<string, list<int>> número do ramal => grupos do SNEP em que ele está */
    private array $gruposDoRamal = [];
    /** @var array<int,true> grupos que alguma regra de saída usa como origem */
    private array $gruposDeSaida = [];
    /** @var list<string> áudios gravados no disco nesta importação (apagados se ela for desfeita) */
    private array $gravados = [];
    /** @var array<int, list<array<string,?string>>> date_alias do SNEP: id => datas */
    private array $datas = [];
    /** @var array<string,string> fila do SNEP => [agente => vezes], do histórico do pacote */
    private array $historico = [];
    private bool $simular = true;
    /** @var array<string,true> "Grupo|item" que a pessoa tirou da importação */
    private array $ignorar = [];

    /** Os grupos em que a pessoa escolhe item por item. */
    public const ESCOLHIVEIS = ['Troncos', 'Ramais', 'Filas', 'Agentes', 'Regras de saída', 'Regras de entrada'];
    /** @var array{sons:array<string,string>, moh:array<string,list<string>>, conf:list<string>}|null */
    private ?array $pacote = null;

    /** @param array<string, Recurso> $recursos */
    public function __construct(private readonly array $recursos, private readonly Request $req)
    {
    }

    /**
     * @param array<string, list<array<string,?string>>> $snep o que SnepDump::ler devolveu
     * @param array{fila_inicio?: string, pacote?: ?array, ignorar?: list<string>} $opcoes
     * @return array<string,mixed>
     */
    public function executar(array $snep, array $opcoes, bool $simular): array
    {
        $this->simular = $simular;
        $this->pacote = $opcoes['pacote'] ?? null;
        $this->ignorar = array_fill_keys(array_map('strval', $opcoes['ignorar'] ?? []), true);
        $this->indexar($snep);

        // Os erros que a central já tinha: a trava do fim só olha o que a
        // migração acrescentou.
        $antes = self::errosDaCentral();
        $cancelada = null;

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            $regras = $this->regrasEmOrdem($snep['regras_negocio'] ?? []);
            $this->indexarGrupos($snep, $regras);
            $this->historico = SnepPacote::historico($this->pacote['historico'] ?? null);
            $this->migrarTroncos($snep['trunks'] ?? []);
            $this->migrarRamais($snep['peers'] ?? []);
            $this->agentesPorFila($snep['queues_agent'] ?? [], $snep['queue_members'] ?? [], $snep['agtfila'] ?? []);
            $this->atalhosDeFila($regras);
            $this->migrarFilas($snep['queues'] ?? [], $snep['queue_members'] ?? [],
                               (string) ($opcoes['fila_inicio'] ?? '8000'));
            $this->migrarAgentes($snep['agtfila'] ?? []);
            $this->migrarSaida($regras);
            $this->migrarEntrada($regras);

            // A trava: a mesma conferência que a atualização da central roda
            // (e que para o playbook quando acha erro). Se a migração
            // acrescentar um erro, nada é gravado — e a prévia mostra qual,
            // para desmarcar o item e tentar de novo.
            $novos = array_values(array_diff(self::errosDaCentral(), $antes));
            foreach ($novos as $erro) {
                $this->linha('Conferência da central', $erro, 'erro',
                    'A central ficaria com este problema. Desmarque o item que o causa ou corrija-o depois de importar o resto.');
            }
            if ($novos !== [] && !$simular) {
                $cancelada = 'A importação foi cancelada e nada foi gravado: ela deixaria a central com '
                           . count($novos) . ' problema(s) que a atualização não aceita. Veja "Conferência da central" '
                           . 'na lista abaixo, desmarque o que os causa e importe de novo.';
            }

            if ($simular || $cancelada !== null) {
                $pdo->rollBack();
                $this->apagarGravados();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->apagarGravados();
            throw $e;
        }

        $resumo = [];
        foreach ($this->linhas as $l) {
            $resumo[$l['acao']] = ($resumo[$l['acao']] ?? 0) + 1;
        }

        return [
            'simulado' => $simular || $cancelada !== null,
            'cancelada' => $cancelada,
            'resumo' => $resumo,
            'linhas' => $this->linhas,
            'audios' => array_values($this->audios),
            'contextos' => array_map(fn (string $chave) => [
                'nome' => explode(',', $chave)[0],
                'texto' => $this->pacote !== null ? SnepPacote::contexto($this->pacote['conf'], explode(',', $chave)[0]) : null,
            ], array_keys($this->contextos)),
            'senhas' => $simular || $cancelada !== null ? [] : $this->senhas,
        ];
    }

    // ------------------------------------------------------------------
    // Troncos
    // ------------------------------------------------------------------

    private function migrarTroncos(array $trunks): void
    {
        foreach ($trunks as $t) {
            $nome = self::nomeDoCallerid((string) ($t['callerid'] ?? '')) ?: 'Tronco ' . $t['name'];
            $item = "{$nome} ({$t['host']})";
            if ($this->pulou('Troncos', $item)) {
                continue;
            }
            $tecnologia = strtoupper((string) ($t['technology'] ?: $t['type']));

            if ($tecnologia !== 'SIP') {
                $this->linha('Troncos', $item, 'erro',
                    "Tronco {$tecnologia}: esta central entronca só por SIP. Cadastre o substituto à mão.");
                continue;
            }

            $hostSnep = strtolower(trim((string) $t['host']));
            if ($hostSnep === '' || $hostSnep === 'dynamic') {
                $this->linha('Troncos', $item, 'erro', $hostSnep === 'dynamic'
                    ? 'O equipamento registrava no SNEP (host dynamic), como um gateway GSM. No Telium o tronco '
                      . 'precisa de um endereço: dê ao gateway um IP fixo e cadastre o tronco com ele, ou cadastre-o '
                      . "como ramal. Usuário no SNEP: {$t['username']}."
                    : 'No SNEP este tronco não tinha host (a operadora ou o SBC chegava por outro caminho, '
                      . "pelo usuário {$t['username']}). Cadastre-o no Telium com o endereço do SBC.");
                continue;
            }

            $existente = Bd::valor('SELECT id FROM troncos WHERE nome = ?', [$nome]);
            if ($existente !== false) {
                $this->troncos[(int) $t['id']] = (int) $existente;
                $this->linha('Troncos', $item, 'existe', 'Já existe um tronco com este nome; ele foi usado como está.');
                continue;
            }

            [$host, $porta] = array_pad(explode(':', (string) $t['host'], 2), 2, '');
            $usuario = trim((string) ($t['username'] ?? ''));
            $senha = (string) ($t['secret'] ?? '');
            $registra = $usuario !== '' && $senha !== '';

            $r = $this->criar('troncos', array_filter([
                'nome' => $nome,
                'tipo' => 'pjsip',
                'host' => $host,
                'porta' => ctype_digit($porta) ? $porta : null,
                'usuario' => $usuario ?: null,
                'senha' => $senha ?: null,
                'from_user' => $usuario ?: null,
                'registrar' => $registra ? 1 : 0,
                'codecs' => self::codecs((string) ($t['allow'] ?? '')),
                'ativo' => (int) ($t['disabled'] ?? 0) === 1 ? 0 : 1,
            ], static fn ($v) => $v !== null));

            if ($r['erro'] !== null) {
                $this->linha('Troncos', $item, 'erro', $r['erro']);
                continue;
            }
            $this->troncos[(int) $t['id']] = $r['id'];
            $this->linha('Troncos', $item, 'criar', $registra
                ? 'Com registro (usuário e senha do SNEP). Confira com a operadora se é por registro ou por IP.'
                : 'Sem registro (autenticação por IP).');
        }
    }

    // ------------------------------------------------------------------
    // Ramais
    // ------------------------------------------------------------------

    private function migrarRamais(array $peers): void
    {
        foreach ($peers as $p) {
            if (($p['peer_type'] ?? 'R') !== 'R') {
                continue;      // o tronco do SNEP também mora em peers
            }
            $numero = trim((string) $p['name']);
            $nome = mb_substr(self::nomeDoCallerid((string) ($p['callerid'] ?? '')) ?: "Ramal {$numero}", 0, 120);
            $item = "{$numero} — {$nome}";
            if ($this->pulou('Ramais', $item)) {
                continue;
            }

            if (preg_match('/^[0-9]{2,10}$/', $numero) !== 1) {
                $this->linha('Ramais', $item, 'erro', 'O número do ramal no Telium é só de dígitos, de 2 a 10.');
                continue;
            }
            if (Bd::valor('SELECT id FROM ramais WHERE numero = ?', [$numero]) !== false) {
                $this->linha('Ramais', $item, 'existe', 'Já existe; não foi alterado.');
                continue;
            }

            // A senha do SNEP vem junto: o telefone volta a registrar sem
            // ninguém mexer nele. Só a curta é trocada — é a porta da
            // fraude de tarifação, e o cadastro não a aceita.
            $senha = (string) ($p['secret'] ?? '');
            $gerada = strlen($senha) < 12;
            if ($gerada) {
                $senha = self::senhaForte();
            }

            // Os grupos do SNEP: o primeiro (fora o "Default") vira o setor;
            // o que uma regra de saída usa vira o grupo de saída.
            $avisosRamal = [];
            $dele = $this->gruposDoRamal[$numero] ?? [];
            $setor = null;
            foreach ($dele as $g) {
                if (strcasecmp($this->grupos[$g] ?? '', 'Default') !== 0) {
                    $setor = mb_substr(trim($this->grupos[$g] ?? ''), 0, 80) ?: null;
                    break;
                }
            }
            $deSaida = array_values(array_filter($dele, fn ($g) => isset($this->gruposDeSaida[$g])));
            $grupoSaida = implode(',', array_unique(array_filter(
                array_map(fn ($g) => self::nomeDeGrupo($this->grupos[$g] ?? ''), $deSaida)
            )));

            $email = trim((string) ($p['email'] ?? ''));
            $r = $this->criar('ramais', array_filter([
                'numero' => $numero,
                'nome' => $nome,
                'setor' => $setor,
                'grupo_saida' => $grupoSaida ?: null,
                'senha_sip' => $senha,
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'callgroup' => trim((string) ($p['callgroup'] ?? '')) ?: null,
                'pickupgroup' => trim((string) ($p['pickupgroup'] ?? '')) ?: null,
                'dtmf_modo' => self::DTMF[strtolower((string) ($p['dtmfmode'] ?? ''))] ?? null,
                'codecs' => self::codecs((string) ($p['allow'] ?? '')),
                'voicemail' => ($p['usa_vc'] ?? 'no') === 'yes' ? 1 : 0,
                'contexto' => 'interno',
                'ativo' => (int) ($p['disabled'] ?? 0) === 1 ? 0 : 1,
            ], static fn ($v) => $v !== null));

            if ($r['erro'] !== null) {
                $this->linha('Ramais', $item, 'erro', $r['erro']);
                continue;
            }
            if ($gerada) {
                $this->senhas[] = ['ramal' => $numero, 'senha' => $senha];
            }
            $this->linha('Ramais', $item, $avisosRamal === [] ? 'criar' : 'aviso', ($gerada
                ? 'Senha SIP nova (a do SNEP tinha menos de 12 caracteres): reconfigure o telefone.'
                : 'Mesma senha SIP do SNEP: o telefone registra sem reconfigurar.')
                . ($setor !== null ? " Setor {$setor}." : '')
                . ($grupoSaida ? " Grupos de saída {$grupoSaida}." : '')
                . ($avisosRamal === [] ? '' : ' ' . ucfirst(implode('; ', $avisosRamal)) . '.'));
        }
    }

    // ------------------------------------------------------------------
    // Filas
    // ------------------------------------------------------------------

    private function migrarFilas(array $queues, array $membros, string $inicio): void
    {
        $proximo = ctype_digit($inicio) ? (int) $inicio : 8000;

        foreach ($queues as $q) {
            $nome = mb_substr(trim((string) $q['name']), 0, 80);
            if ($this->pulou('Filas', $nome)) {
                continue;
            }

            $existente = Bd::valor('SELECT numero FROM filas WHERE nome = ?', [$nome]);
            if ($existente !== false) {
                $this->filas[(string) $q['name']] = (string) $existente;
                $this->linha('Filas', $nome, 'existe', "Já existe uma fila com este nome (número {$existente}); não foi alterada.");
                continue;
            }

            // O SNEP chama a fila pelo nome; no Telium ela tem número. Se uma
            // regra do SNEP levava um número interno a ela (5600 => Suporte),
            // é esse; senão, o próximo livre a partir do escolhido.
            $avisos = [];
            $interno = $this->numeroDaFila[(string) $q['name']] ?? null;
            if ($interno !== null && !$this->numeroOcupado($interno)) {
                $numero = $interno;
            } else {
                if ($interno !== null) {
                    $avisos[] = "o número interno {$interno} do SNEP é de um ramal aqui (no SNEP, a regra passava "
                              . 'na frente do ramal); para os ramais discarem esse número e cair na fila, troque o '
                              . 'número do ramal ou o da fila';
                }
                while ($this->numeroOcupado((string) $proximo)) {
                    $proximo++;
                }
                $numero = (string) $proximo++;
            }

            $musica = trim((string) ($q['musiconhold'] ?? ''));
            $classe = 'default';
            if ($musica !== '' && $musica !== 'default') {
                $audio = $this->audio($musica, "música de espera da fila {$nome}", 'espera')
                      ?? $this->musicaDoPacote($musica);
                if ($audio !== null) {
                    $classe = (string) $audio['arquivo'];
                } else {
                    $avisos[] = "música de espera \"{$musica}\" ainda não está em Áudios (ficou a padrão)";
                }
            }
            foreach (['announce' => 'anúncio de entrada', 'periodic_announce' => 'anúncio periódico'] as $c => $o) {
                if (trim((string) ($q[$c] ?? '')) !== '') {
                    $avisos[] = "{$o} \"{$q[$c]}\" não foi migrado: escolha-o na fila";
                }
            }

            // Fila com agente do SNEP vira fila de call center: é onde o
            // Telium põe agente com login. E fila de call center não tem
            // membro fixo — quem atende é quem está logado.
            $callcenter = isset($this->agentesDaFila[(string) $q['name']]);

            $estrategia = strtolower((string) ($q['strategy'] ?? ''));
            $r = $this->criar('filas', array_filter([
                'numero' => $numero,
                'nome' => $nome,
                'descricao' => 'Migrada do SNEP',
                'callcenter' => $callcenter ? 1 : 0,
                'estrategia' => in_array($estrategia, self::ESTRATEGIAS, true) ? $estrategia : 'ringall',
                'timeout_agente' => self::inteiro($q['timeout'] ?? null, 5),
                'retry' => self::inteiro($q['retry'] ?? null, 1),
                'wrapuptime' => self::inteiro($q['wrapuptime'] ?? null, 0),
                'max_chamadas' => self::inteiro($q['maxlen'] ?? null, 1),
                'sla_segundos' => self::inteiro($q['servicelevel'] ?? null, 1),
                'peso' => self::inteiro($q['weight'] ?? null, 1),
                'max_espera' => self::inteiro($q['max_time_call'] ?? null, 1),
                'entrar_vazia' => self::vazia($q['joinempty'] ?? null),
                'sair_vazia' => self::vazia($q['leavewhenempty'] ?? null),
                'tocar_ocupado' => isset($q['ringinuse']) ? ((int) $q['ringinuse'] === 1 ? 1 : 0) : null,
                'musica_espera' => $classe,
                'ativo' => 1,
            ], static fn ($v) => $v !== null));

            if ($r['erro'] !== null) {
                $this->linha('Filas', $nome, 'erro', $r['erro']);
                continue;
            }
            $this->filas[(string) $q['name']] = $numero;
            if ($callcenter) {
                $this->filasCallCenter[(string) $q['name']] = $r['id'];
            }

            // Os membros fixos da fila: no SNEP, a interface do ramal.
            $agentes = [];
            $fixosDeFora = [];
            foreach ($membros as $m) {
                if ((string) $m['queue_name'] !== (string) $q['name']) {
                    continue;
                }
                $interface = (string) $m['interface'];
                if (preg_match('#^Agent/\d+$#i', $interface) === 1) {
                    continue;      // agente do SNEP: vem em migrarAgentes
                }
                if (preg_match('#^(?:SIP|PJSIP|IAX2)/(\d+)$|^Local/(\d+)@#', $interface, $x) !== 1) {
                    $avisos[] = "membro {$interface} não é um ramal";
                    continue;
                }
                if ($callcenter) {
                    $fixosDeFora[] = $x[1] !== '' ? $x[1] : $x[2];
                    continue;
                }
                $ramal = $x[1] !== '' ? $x[1] : $x[2];
                if (Bd::valor('SELECT id FROM ramais WHERE numero = ?', [$ramal]) === false) {
                    $avisos[] = "membro {$ramal} não existe como ramal no Telium";
                    continue;
                }
                $agentes[] = ['ramal' => $ramal, 'penalidade' => (int) ($m['penalty'] ?? 0)];
            }
            if ($agentes !== []) {
                $resposta = (new Filas())->salvarAgentes(
                    $this->req->withParsedBody(['agentes' => $agentes]), new SlimResponse(), ['id' => $r['id']]
                );
                if ($resposta->getStatusCode() >= 400) {
                    $avisos[] = 'membros não gravados: ' . self::erroDe($resposta);
                }
            }

            if ($fixosDeFora !== []) {
                $avisos[] = 'os ramais fixos ' . implode(', ', $fixosDeFora) . ' ficaram de fora: numa fila de '
                          . 'call center só atende agente logado (crie um agente para cada um, se precisar)';
            }
            $this->linha('Filas', $nome, $avisos === [] ? 'criar' : 'aviso',
                ($callcenter
                    ? "Número {$numero}, fila de call center com "
                      . count($this->agentesDaFila[(string) $q['name']]) . ' agente(s) do SNEP.'
                    : "Número {$numero}, " . count($agentes) . ' membro(s).')
                . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.'));
        }
    }

    private function numeroOcupado(string $numero): bool
    {
        foreach (['ramais', 'filas', 'grupos_toque', 'conferencias'] as $tabela) {
            if (Bd::valor("SELECT 1 FROM `{$tabela}` WHERE numero = ?", [$numero]) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * As filas de cada agente: queues_agent, os membros Agent/<n> da fila
     * e, do pacote, as filas em que ele entrou nos últimos 90 dias (no SNEP
     * o agente escolhe a fila no login: é o histórico que diz quais são as
     * dele). No histórico o agente aparece pelo nome, por "Agent/<n>" ou
     * pela matrícula.
     */
    private function agentesPorFila(array $queuesAgent, array $membros, array $agtfila): void
    {
        $porNome = [];
        foreach ($agtfila as $a) {
            $porNome[mb_strtolower(trim((string) $a['nome']))] = (string) $a['numero'];
            $porNome[(string) $a['numero']] = (string) $a['numero'];
        }
        foreach ($this->historico as $fila => $agentes) {
            $fila = (string) $fila;
            foreach ($agentes as $quem => $vezes) {
                $quem = (string) $quem;      // a matrícula vira chave numérica no PHP
                $chave = preg_match('#^Agent/(\d+)$#i', $quem, $x) === 1 ? $x[1] : mb_strtolower(trim($quem));
                if (isset($porNome[$chave])) {
                    $this->agentesDaFila[$fila][] = ['agente' => $porNome[$chave], 'penalidade' => 0, 'historico' => $vezes];
                }
            }
        }

        foreach ($queuesAgent as $qa) {
            $this->agentesDaFila[(string) $qa['queue']][] = [
                'agente' => (string) $qa['agent_id'], 'penalidade' => (int) ($qa['penalty'] ?? 0),
            ];
        }
        foreach ($membros as $m) {
            if (preg_match('#^Agent/(\d+)$#i', (string) $m['interface'], $x) === 1) {
                $this->agentesDaFila[(string) $m['queue_name']][] = [
                    'agente' => $x[1], 'penalidade' => (int) ($m['penalty'] ?? 0),
                ];
            }
        }
    }

    // ------------------------------------------------------------------
    // Agentes (login na fila por código)
    // ------------------------------------------------------------------

    private function migrarAgentes(array $agentes): void
    {
        foreach ($agentes as $a) {
            $matricula = (string) $a['numero'];
            $nome = mb_substr(trim((string) $a['nome']) ?: "Agente {$matricula}", 0, 80);
            $item = "{$matricula} — {$nome}";
            if ($this->pulou('Agentes', $item)) {
                continue;
            }

            if (Bd::valor('SELECT id FROM cc_agentes WHERE matricula = ?', [$matricula]) !== false) {
                $this->linha('Agentes', $item, 'existe', 'Já existe um agente com esta matrícula; não foi alterado.');
                continue;
            }

            $filas = [];
            $nomes = [];
            foreach ($this->agentesDaFila as $fila => $lista) {
                foreach ($lista as $x) {
                    if ($x['agente'] === $matricula && isset($this->filasCallCenter[$fila])
                        && !in_array($fila, $nomes, true)) {
                        $filas[] = ['fila_id' => $this->filasCallCenter[$fila], 'penalidade' => min(9, $x['penalidade'])];
                        $nomes[] = $fila;
                    }
                }
            }

            $avisos = [];
            [$pin, $avisoPin] = self::pinDoSnep((string) ($a['password'] ?? ''));
            if ($avisoPin !== null) {
                $avisos[] = $avisoPin;
            }

            $resposta = (new CallCenter())->salvar(
                // Agente novo não está logado: não há o que sincronizar no
                // Asterisk. Sem o AMI, a prévia não espera o timeout de cada um.
                $this->req->withAttribute('sem_ami', true)->withParsedBody(array_filter([
                    'nome' => $nome, 'matricula' => $matricula, 'pin' => $pin, 'filas' => $filas,
                    'ativo' => (string) ($a['status'] ?? '1') === '0' ? 0 : 1,
                ], static fn ($v) => $v !== null)),
                new SlimResponse(),
                []
            );
            if ($resposta->getStatusCode() >= 400) {
                $this->linha('Agentes', $item, 'erro', self::erroDe($resposta));
                continue;
            }
            if ($nomes === []) {
                $avisos[] = $this->historico === []
                    ? 'no SNEP não tinha fila fixa (escolhia no login): ponha-o nas filas no Call Center '
                      . '(com o pacote, as filas vêm do histórico)'
                    : 'não entrou em nenhuma fila nos últimos 90 dias: ponha-o nas filas no Call Center';
            }
            if ((string) ($a['status'] ?? '1') === '0') {
                $avisos[] = 'estava desativado no SNEP e ficou desativado';
            }
            $this->linha('Agentes', $item, $avisos === [] ? 'criar' : 'aviso',
                'Entra pelo *11 com a mesma matrícula'
                . ($pin !== null ? ' e a mesma senha do SNEP' : '')
                . ($nomes !== [] ? ', nas filas ' . implode(', ', array_unique($nomes)) : '') . '.'
                . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.'));
        }
    }

    /**
     * A senha do agente do SNEP (um número) como PIN do Telium (4 a 8
     * dígitos). O SNEP guardava em coluna numérica: "0123" virou 123.
     *
     * @return array{0: ?string, 1: ?string} o PIN e um aviso
     */
    private static function pinDoSnep(string $senha): array
    {
        $senha = trim($senha);
        if ($senha === '' || $senha === '0') {
            return [null, 'o agente não tinha senha no SNEP: ficou sem PIN (defina um no Call Center)'];
        }
        if (!ctype_digit($senha) || strlen($senha) > 8) {
            return [null, 'a senha do SNEP não serve como PIN (4 a 8 dígitos): ficou sem PIN'];
        }
        if (strlen($senha) < 4) {
            return [str_pad($senha, 4, '0', STR_PAD_LEFT),
                    'a senha do SNEP tinha menos de 4 dígitos: o PIN ficou ' . str_pad($senha, 4, '0', STR_PAD_LEFT)];
        }

        return [$senha, null];
    }

    // ------------------------------------------------------------------
    // Regras de saída
    // ------------------------------------------------------------------

    private function migrarSaida(array $regras): void
    {
        $ordem = 0;
        $usados = [];      // padrão => rota que já o tem
        foreach ($regras as $r) {
            if (!$this->ehSaida($r)) {
                continue;
            }
            $acoes = $this->acoes[(int) $r['id']] ?? [];
            $discar = self::acao($acoes, 'discartronco');
            $nome = $this->nomeDaRegra($r);
            $ordem += 10;
            if ($this->pulou('Regras de saída', $nome)) {
                continue;
            }

            if ($discar === null) {
                $bloqueio = false;
                foreach ($acoes as $a) {
                    $bloqueio = $bloqueio || ($a['acao'] === 'rewrite' && strtolower((string) ($a['cfg']['replace'] ?? '')) === 'hangup');
                }
                $this->linha('Regras de saída', $nome, 'ignorado', $bloqueio
                    ? "Bloqueio de {$r['origem']} no SNEP: no Telium, use a Lista negra ou as permissões de discagem."
                    : 'A regra não discava por tronco nenhum: não há o que migrar.');
                continue;
            }
            // Regra só de um grupo ou ramal (o NOC sai pelo tronco A, o
            // suporte pelo B) vira rota "só para": os ramais do grupo saem
            // por ela, e o resto pela geral do mesmo padrão.
            $grupos = $this->gruposDaOrigem((string) $r['origem'], $avisosOrigem);

            if (Bd::valor('SELECT id FROM rotas_saida WHERE nome = ?', [$nome]) !== false) {
                $this->linha('Regras de saída', $nome, 'existe', 'Já existe uma rota com este nome; não foi alterada.');
                continue;
            }
            $tronco = $this->troncos[(int) ($discar['cfg']['tronco'] ?? 0)] ?? null;
            if ($tronco === null) {
                $this->linha('Regras de saída', $nome, 'erro',
                    'O tronco desta regra não foi migrado (veja Troncos).');
                continue;
            }

            $avisos = $avisosOrigem;
            $horario = null;
            if (!self::semHorario($r)) {
                $horario = $this->grupoDeHorario($r, $avisos);
            }
            $adicionar = '';
            foreach ($acoes as $a) {
                if ($a['acao'] === 'discartronco') {
                    break;
                }
                if ($a['acao'] === 'rewrite') {
                    $adicionar .= preg_replace('/\D/', '', (string) ($a['cfg']['prefix'] ?? '')) ?? '';
                    // "pipecut" é cortar no "|": é o prefixo a tirar de cada padrão.
                    $corte = (string) ($a['cfg']['cut'] ?? 'nocut');
                    if (!in_array($corte, ['', 'nocut', 'pipecut'], true) || ($a['cfg']['replace'] ?? '') !== ''
                        || ($a['cfg']['suffix'] ?? '') !== '') {
                        $avisos[] = 'a reescrita do número (corte, troca ou sufixo) não foi migrada: confira os padrões';
                    }
                }
            }

            $padroes = [];
            foreach ($this->expressoes((string) $r['destino']) as $expressao) {
                [$remover, $resto] = str_contains($expressao, '|')
                    ? explode('|', $expressao, 2) : ['', $expressao];
                // "0XX|..." tirava o 0 e o código da operadora, com
                // curinga; aqui o prefixo a tirar é só de dígitos.
                if (preg_match('/^[0-9*#+]*$/', $remover) !== 1) {
                    $avisos[] = "o padrão {$expressao} tirava \"{$remover}\" (com curinga) e não foi migrado: "
                              . 'cadastre-o à mão';
                    continue;
                }
                $chave = \Telium\Gerador\Padrao::normalizar($remover . $resto) . '|' . $grupos . '|' . ($horario ?? '');
                if (isset($usados[$chave])) {
                    $avisos[] = "o padrão {$expressao} já está na rota {$usados[$chave]} e ficou só lá";
                    continue;
                }
                $padroes[] = ['padrao' => $remover . $resto, 'prefixo_remover' => $remover,
                              'prefixo_adicionar' => $adicionar];
            }
            if ($padroes === []) {
                $this->linha('Regras de saída', $nome, 'erro', "O destino \"{$r['destino']}\" não tem padrão de número.");
                continue;
            }
            $rota = $this->criar('rotas-saida', [
                'nome' => $nome,
                'ordem' => $ordem,
                'tronco_id' => $tronco,
                'classe' => self::classe($nome . ' ' . $this->nomesDeAlias((string) $r['destino'])),
                'grupos' => $grupos !== '' ? $grupos : null,
                'grupo_horario_id' => $horario,
                'ativo' => (int) $r['ativa'] === 1 ? 1 : 0,
                'padroes' => $padroes,
            ]);
            if ($rota['erro'] !== null) {
                $this->linha('Regras de saída', $nome, 'erro', $rota['erro']);
                continue;
            }
            foreach ($padroes as $p) {
                $usados[\Telium\Gerador\Padrao::normalizar($p['padrao']) . '|' . $grupos . '|' . ($horario ?? '')] = $nome;
            }
            $this->linha('Regras de saída', $nome, $avisos === [] ? 'criar' : 'aviso',
                ($grupos !== '' ? "Só para {$grupos}. " : '')
                . ($horario !== null ? 'Só em ' . $this->textoDoHorario($r) . ' (fora dele, segue para a próxima rota do número). ' : '')
                . 'Padrões: ' . implode(', ', array_map(
                    static fn ($p) => $p['padrao'] . ($p['prefixo_remover'] !== '' ? " (tira {$p['prefixo_remover']})" : '')
                        . ($p['prefixo_adicionar'] !== '' ? " (põe {$p['prefixo_adicionar']})" : ''),
                    $padroes
                )) . '.' . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.'));
        }
    }

    /**
     * "G:7,R:3602" => "NOC,3602": os grupos de saída e os ramais de uma
     * origem do SNEP. "G:all" e "X" são todos: lista vazia.
     *
     * @param list<string>|null $avisos
     */
    private function gruposDaOrigem(string $origem, ?array &$avisos): string
    {
        $avisos = [];
        $lista = [];
        foreach (array_filter(array_map('trim', explode(',', $origem))) as $o) {
            if ($o === 'G:all' || $o === 'X') {
                return '';
            }
            if (preg_match('/^G:(\d+)$/', $o, $m) === 1) {
                $nome = self::nomeDeGrupo($this->grupos[(int) $m[1]] ?? '');
                if ($nome === '') {
                    $avisos[] = "o grupo {$o} do SNEP não existe no dump";
                    continue;
                }
                $lista[] = $nome;
            } elseif (preg_match('/^R:(\d{2,10})$/', $o, $m) === 1) {
                $lista[] = $m[1];
            } else {
                $avisos[] = "a origem {$o} do SNEP não foi migrada";
            }
        }

        return implode(',', array_unique($lista));
    }

    /** "NOC  " => "NOC", "Pós Vendas" => "Pos-Vendas": nome que cabe numa expressão do dialplan. */
    private static function nomeDeGrupo(string $nome): string
    {
        $sem = iconv('UTF-8', 'ASCII//TRANSLIT', trim($nome)) ?: trim($nome);

        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $sem), '-'), 0, 40);
    }

    /** Os grupos do SNEP e quem está em cada um; e quais deles as regras de saída usam. */
    private function indexarGrupos(array $snep, array $regras): void
    {
        foreach ($snep['core_groups'] ?? [] as $g) {
            $this->grupos[(int) $g['id']] = (string) $g['name'];
        }
        $numeros = [];
        foreach ($snep['peers'] ?? [] as $p) {
            $numeros[(int) $p['id']] = trim((string) $p['name']);
        }
        foreach ($snep['core_peer_groups'] ?? [] as $pg) {
            $numero = $numeros[(int) $pg['peer_id']] ?? null;
            if ($numero !== null) {
                $this->gruposDoRamal[$numero][] = (int) $pg['group_id'];
            }
        }
        foreach ($regras as $r) {
            if ($this->ehSaida($r) && preg_match_all('/G:(\d+)/', (string) $r['origem'], $m) > 0) {
                foreach ($m[1] as $id) {
                    $this->gruposDeSaida[(int) $id] = true;
                }
            }
        }
    }

    /** As expressões de número de um destino do SNEP (AL:, RX:, R:, X). */
    private function expressoes(string $destino): array
    {
        $lista = [];
        foreach (array_filter(array_map('trim', explode(',', $destino))) as $parte) {
            if (str_starts_with($parte, 'AL:')) {
                array_push($lista, ...($this->alias[(int) substr($parte, 3)]['expressoes'] ?? []));
            } elseif (preg_match('/^RX?:(.+)$/', $parte, $m) === 1) {
                $lista[] = $m[1];
            } elseif ($parte === 'X') {
                $lista[] = 'X.';
            }
        }

        return array_values(array_unique($lista));
    }

    private function nomesDeAlias(string $destino): string
    {
        preg_match_all('/AL:(\d+)/', $destino, $m);

        return implode(' ', array_map(fn ($id) => $this->alias[(int) $id]['nome'] ?? '', $m[1]));
    }

    // ------------------------------------------------------------------
    // Regras de entrada
    // ------------------------------------------------------------------

    private function migrarEntrada(array $regras): void
    {
        // As opções da URA do SNEP são regras: a URA disca <prefixo>-<tecla>
        // e a regra com esse destino diz o que acontece. Elas viram as
        // entradas da URA, e não rotas de entrada.
        $opcoes = [];
        foreach ($regras as $r) {
            foreach ($this->acoes[(int) $r['id']] ?? [] as $a) {
                if ($a['acao'] === 'ivr') {
                    $prefixo = (string) ($a['cfg']['prefix'] ?? '');
                    foreach (self::teclas((string) ($a['cfg']['digits'] ?? '')) as $tecla) {
                        $opcoes["{$prefixo}-{$tecla}"] = true;
                        $opcoes["{$prefixo}{$tecla}"] = true;
                    }
                }
            }
        }

        // O mesmo número pode ter mais de uma regra (uma no horário, outra
        // fora dele): elas viram uma rota só, com condição horária.
        $grupos = [];
        foreach ($regras as $r) {
            if ($this->ehSaida($r)) {
                continue;
            }
            $acoes = $this->acoes[(int) $r['id']] ?? [];
            $nome = $this->nomeDaRegra($r, 120);
            $destinos = array_values(array_filter(array_map('trim', explode(',', (string) $r['destino']))));

            if (count($destinos) === 1 && preg_match('/^RX?:(.+)$/', $destinos[0], $m) === 1 && isset($opcoes[$m[1]])) {
                continue;      // vira entrada da URA, junto com ela
            }
            if (isset($this->atalhos[(int) $r['id']])) {
                ['fila' => $fila, 'numero' => $n] = $this->atalhos[(int) $r['id']];
                $final = $this->filas[$fila] ?? null;
                $this->linha('Regras de entrada', $nome, 'ignorado', $final === $n
                    ? "Número interno {$n} da fila {$fila}: a fila ficou com este número."
                    : "Número interno {$n} da fila {$fila}: não migrado"
                      . ($final !== null ? " (a fila ficou com o {$final})." : ' (a fila não foi migrada).'));
                continue;
            }
            $contexto = self::acao($acoes, 'gocontext');
            if ($contexto !== null && preg_match('/^ACD/i', (string) ($contexto['cfg']['context'] ?? '')) === 1) {
                $this->linha('Regras de entrada', $nome, 'ignorado',
                    "Código do call center do SNEP (contexto {$contexto['cfg']['context']}). No Telium, o agente entra "
                    . 'e pausa pelos códigos do Call Center, e cada motivo de pausa tem o seu código.');
                continue;
            }
            if (self::acao($acoes, 'discarramal') !== null && array_diff($destinos, ['G:all', 'X']) === []) {
                $this->linha('Regras de entrada', $nome, 'ignorado',
                    'Ligação de ramal para ramal: o Telium já faz, sem regra.');
                continue;
            }
            if (!self::origemExterna((string) $r['origem'])) {
                $this->linha('Regras de entrada', $nome, 'ignorado',
                    "Atalho interno (os ramais discavam {$r['destino']}). No Telium, a rota de entrada é para "
                    . 'chamadas que chegam pelos troncos: use o número da fila, de um grupo ou um ramal com siga-me.');
                continue;
            }

            $dids = [];
            foreach ($destinos as $d) {
                if (preg_match('/^RX?:(.+)$/', $d, $m) === 1) {
                    $dids[] = str_replace('|', '', $m[1]);
                } elseif ($d === 'X') {
                    $dids[] = 'X.';
                }
            }
            if ($dids === []) {
                $this->linha('Regras de entrada', $nome, 'erro', "O destino \"{$r['destino']}\" não é um número chamado.");
                continue;
            }
            $cid = '';
            foreach (array_filter(array_map('trim', explode(',', (string) $r['origem']))) as $o) {
                if (preg_match('/^RX:(.+)$/', $o, $m) === 1) {
                    $cid = str_replace('|', '', $m[1]);
                }
            }
            foreach (array_unique($dids) as $did) {
                $chave = \Telium\Gerador\Padrao::normalizar($did) . "\0{$cid}";
                $grupos[$chave] ??= ['did' => $did, 'cid' => $cid, 'regras' => []];
                $grupos[$chave]['regras'][] = $r;
            }
        }

        foreach ($grupos as $g) {
            $this->rotaDeEntrada($g['did'], $g['cid'], $g['regras']);
        }
    }

    /** Uma rota de entrada para o número, com as regras que o SNEP tinha para ele. */
    private function rotaDeEntrada(string $did, string $cid, array $regras): void
    {
        $principal = $regras[0];
        $nome = $this->nomeDaRegra($principal, 120);
        $item = "{$nome} ({$did})";
        $avisos = [];
        if ($this->pulou('Regras de entrada', $item)) {
            return;
        }

        if (Bd::valor('SELECT id FROM rotas_entrada WHERE did = ? AND cid_origem = ?',
                      [\Telium\Gerador\Padrao::normalizar($did), $cid]) !== false) {
            $this->linha('Regras de entrada', $item, 'existe', 'Já existe uma rota para este número; não foi alterada.');
            return;
        }

        $destino = $this->encadear($regras, $did, $avisos);
        if ($destino === null) {
            $this->linha('Regras de entrada', $item, 'erro', ucfirst(implode('; ', array_unique($avisos))) . '.');
            return;
        }

        $rota = $this->criar('rotas-entrada', [
            'did' => $did,
            'descricao' => $nome,
            'cid_origem' => $cid,
            'destino_tipo' => $destino[0],
            'destino_valor' => $destino[1],
            'gravar' => (int) $principal['record'] === 1 ? 1 : 0,
            'ordem' => 10 + (int) $principal['prio'],
            'ativo' => (int) $principal['ativa'] === 1 ? 1 : 0,
        ]);
        $avisos = array_unique($avisos);
        $this->linha('Regras de entrada', $item, $rota['erro'] !== null ? 'erro' : ($avisos === [] ? 'criar' : 'aviso'),
            $rota['erro'] ?? ("Vai para {$this->descrever($destino)}." . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.')));
    }

    /**
     * As regras de um número, na ordem em que o SNEP as testa (prioridade
     * maior primeiro): a primeira que vale na hora atende. Regra sem
     * horário fecha a lista; com horário, vira condição horária — dentro
     * dela, o destino da regra; fora, o resto da lista. Assim "manhã",
     * "tarde" e "noite" do mesmo número viram condições encadeadas.
     *
     * @param list<array<string,?string>> $regras
     * @return array{0:string,1:string}|null
     */
    private function encadear(array $regras, string $did, array &$avisos): ?array
    {
        if ($regras === []) {
            $avisos[] = 'fora dos horários das regras o SNEP não tinha regra para este número: aqui, desliga';

            return ['desligar', ''];
        }
        $r = $regras[0];
        $resto = array_slice($regras, 1);

        if (self::semHorario($r)) {
            foreach ($resto as $outra) {
                $avisos[] = 'a regra "' . trim((string) $outra['desc']) . '" também era deste número, mas no SNEP '
                          . 'nunca era alcançada (a "' . trim((string) $r['desc']) . '" vale o tempo todo e vem antes)';
            }

            return $this->destinoDaRegra($r, $did, $avisos);
        }

        $dentro = $this->destinoDaRegra($r, $did, $avisos);
        if ($dentro === null) {
            return null;
        }
        // O aviso desta regra vem antes dos da próxima: na ordem em que o
        // SNEP as testa.
        $marca = count($avisos);
        $fora = $this->encadear($resto, $did, $avisos) ?? ['desligar', ''];

        $nome = 'SNEP: ' . $this->nomeDaRegra($r, 74);
        $existente = Bd::valor('SELECT id FROM condicoes_horarias WHERE nome = ?', [$nome]);
        if ($existente !== false) {
            return ['condicao', (string) $existente];
        }
        $grupo = $this->grupoDeHorario($r, $avisos);
        if ($grupo === null) {
            return null;
        }
        $c = $this->criar('condicoes-horarias', [
            'nome' => $nome,
            'descricao' => 'Migrada do SNEP',
            'grupo_horario_id' => $grupo,
            'destino_dentro_tipo' => $dentro[0], 'destino_dentro_valor' => $dentro[1],
            'destino_fora_tipo' => $fora[0], 'destino_fora_valor' => $fora[1],
            'ativo' => 1,
        ]);
        if ($c['erro'] !== null) {
            $avisos[] = "a condição horária não foi criada: {$c['erro']}";
            return null;
        }
        array_splice($avisos, $marca, 0, ['em ' . $this->textoDoHorario($r) . ' vai para ' . $this->descrever($dentro)
                  . '; fora dele, para ' . $this->descrever($fora)]);

        return ['condicao', (string) $c['id']];
    }

    /**
     * O grupo de horário de uma regra do SNEP ("SNEP: <regra>"): as faixas
     * da validade nos dias da semana dela, ou as datas do alias de datas.
     */
    private function grupoDeHorario(array $r, array &$avisos): ?int
    {
        $nome = 'SNEP: ' . $this->nomeDaRegra($r, 74);
        $existente = Bd::valor('SELECT id FROM grupos_horario WHERE nome = ?', [$nome]);
        if ($existente !== false) {
            return (int) $existente;
        }

        $g = $this->criar('grupos-horario', ['nome' => $nome, 'descricao' => 'Horário de uma regra do SNEP']);
        if ($g['erro'] !== null) {
            $avisos[] = "o grupo de horário não foi criado: {$g['erro']}";
            return null;
        }
        $resposta = (new Horarios())->salvarFaixas(
            $this->req->withParsedBody(['faixas' => $this->faixasDaRegra($r, $avisos)]), new SlimResponse(), ['id' => $g['id']]
        );
        if ($resposta->getStatusCode() >= 400) {
            $avisos[] = 'as faixas do horário não foram gravadas: ' . self::erroDe($resposta);
            return null;
        }

        return $g['id'];
    }

    /**
     * As faixas de horário de uma regra, no formato do grupo de horário.
     * Faixa que vira a meia-noite (18:00-07:59), que o SNEP aceita, vira
     * duas. Com alias de datas, valem as datas (dia e mês, com a faixa de
     * hora de cada uma), como no SNEP.
     *
     * @return list<array<string,mixed>>
     */
    private function faixasDaRegra(array $r, array &$avisos): array
    {
        $faixas = [];
        $ids = array_filter(array_map('intval', explode(',', (string) ($r['dates_alias'] ?? ''))));
        if ($ids !== []) {
            foreach ($ids as $id) {
                foreach ($this->datas[$id] ?? [] as $d) {
                    $data = self::diaEMes((string) $d['date']);
                    if ($data === null) {
                        $avisos[] = "a data \"{$d['date']}\" do SNEP não foi entendida";
                        continue;
                    }
                    [$dia, $mes, $ano] = $data;
                    if ($ano !== null) {
                        $avisos[] = "a data {$d['date']} era de {$ano}; aqui vale todo ano nesse dia (confira os feriados móveis)";
                    }
                    [$ini, $fim] = array_pad(explode('-', (string) ($d['timerange'] ?: '00:00-23:59'), 2), 2, '23:59');
                    foreach (self::partirMeiaNoite(trim($ini), trim($fim)) as [$i, $f]) {
                        $faixas[] = ['hora_inicio' => $i, 'hora_fim' => $f, 'dias' => '*',
                                     'dia_mes_inicio' => $dia, 'dia_mes_fim' => $dia,
                                     'mes_inicio' => $mes, 'mes_fim' => $mes];
                    }
                }
            }
            if (!self::semHorarioDaSemana($r)) {
                $avisos[] = 'a regra tinha datas e também horário da semana; aqui valem as datas';
            }

            return $faixas;
        }

        foreach (array_filter(array_map('trim', explode(',', (string) $r['validade']))) as $faixa) {
            [$ini, $fim] = array_pad(explode('-', $faixa, 2), 2, '');
            foreach (self::partirMeiaNoite($ini, $fim) as [$i, $f]) {
                $faixas[] = ['hora_inicio' => $i, 'hora_fim' => $f, 'dias' => (string) $r['diasDaSemana']];
            }
        }

        return $faixas;
    }

    /** @return list<array{0:string,1:string}> */
    private static function partirMeiaNoite(string $ini, string $fim): array
    {
        // Em minutos: "8:00" > "18:00" como texto, e o SNEP aceita hora sem zero.
        $minutos = static fn (string $h): int => preg_match('/^(\d{1,2}):(\d{2})/', trim($h), $m) === 1
            ? (int) $m[1] * 60 + (int) $m[2] : 0;

        return $fim !== '' && $minutos($ini) > $minutos($fim)
            ? [[$ini, '23:59'], ['00:00', $fim]]
            : [[$ini, $fim]];
    }

    /** "2026-12-25", "25/12/2026", "25/12", "12-25" => [dia, mês, ano|null] */
    private static function diaEMes(string $data): ?array
    {
        $data = trim($data);

        return match (true) {
            (bool) preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $data, $m) => [(int) $m[3], (int) $m[2], $m[1]],
            (bool) preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $data, $m) => [(int) $m[1], (int) $m[2], $m[3]],
            (bool) preg_match('#^(\d{1,2})/(\d{1,2})$#', $data, $m) => [(int) $m[1], (int) $m[2], null],
            (bool) preg_match('/^(\d{1,2})-(\d{1,2})$/', $data, $m) => [(int) $m[2], (int) $m[1], null],
            default => null,
        };
    }

    private function textoDoHorario(array $r): string
    {
        if (trim((string) ($r['dates_alias'] ?? '')) !== '') {
            return 'datas do SNEP (' . $r['dates_alias'] . ')';
        }

        return "{$r['validade']} ({$r['diasDaSemana']})";
    }

    /** O destino de uma regra, resolvido uma vez só (a regra pode ter vários números). */
    private function destinoDaRegra(array $r, string $did, array &$avisos): ?array
    {
        $id = (int) $r['id'];
        // Por regra E número: "discar o próprio número" e a reescrita usam o
        // número chamado — cada DID da regra tem o seu destino.
        $chave = "{$id}|{$did}";
        if (!isset($this->destinosDasRegras[$chave])) {
            $proprios = [];
            $nome = $this->nomeDaRegra($r, 120);
            $destino = $this->resolver($this->acoes[$id] ?? [], $nome, $did, $proprios);
            $this->destinosDasRegras[$chave] = ['destino' => $destino, 'avisos' => $proprios];
        }
        array_push($avisos, ...$this->destinosDasRegras[$chave]['avisos']);

        return $this->destinosDasRegras[$chave]['destino'];
    }

    /** Regra de saída: a do tipo "outgoing", ou a que disca por tronco a partir de um ramal. */
    private function ehSaida(array $r): bool
    {
        $tipo = (string) ($r['type'] ?? '');
        if ($tipo === 'outgoing') {
            return true;
        }

        return $tipo !== 'incoming'
            && self::acao($this->acoes[(int) $r['id']] ?? [], 'discartronco') !== null
            && !self::origemExterna((string) $r['origem']);
    }

    /**
     * Os números internos que levavam a uma fila (os ramais discavam 5600
     * e caíam na fila Suporte). No Telium a fila tem número: fica esse.
     */
    private function atalhosDeFila(array $regras): void
    {
        foreach ($regras as $r) {
            if ($this->ehSaida($r) || self::origemExterna((string) $r['origem'])) {
                continue;
            }
            $acoes = array_values(array_filter($this->acoes[(int) $r['id']] ?? [], static fn ($a) => $a['acao'] !== 'ccustos'));
            if (($acoes[0]['acao'] ?? '') !== 'queue') {
                continue;
            }
            $fila = (string) ($acoes[0]['cfg']['queue'] ?? '');
            foreach (array_map('trim', explode(',', (string) $r['destino'])) as $d) {
                if (preg_match('/^RX?:([0-9]{2,10})$/', $d, $m) === 1) {
                    $this->numeroDaFila[$fila] ??= $m[1];
                    $this->atalhos[(int) $r['id']] = ['fila' => $fila, 'numero' => $m[1]];
                    break;
                }
            }
        }
    }

    /** A chamada vem de fora? T: (tronco), X (qualquer um) ou RX: (número de quem liga). */
    private static function origemExterna(string $origem): bool
    {
        $itens = array_filter(array_map('trim', explode(',', $origem)));
        if ($itens === []) {
            return true;
        }
        foreach ($itens as $o) {
            if ($o === 'X' || str_starts_with($o, 'T:') || str_starts_with($o, 'RX:')) {
                return true;
            }
        }

        return false;
    }

    private static function semHorario(array $r): bool
    {
        return self::semHorarioDaSemana($r) && trim((string) ($r['dates_alias'] ?? '')) === '';
    }

    private static function semHorarioDaSemana(array $r): bool
    {
        $validade = trim((string) ($r['validade'] ?? ''));

        return ($validade === '' || $validade === '00:00:00-23:59:59' || $validade === '00:00:00-23:59:00')
            && count(array_filter(explode(',', (string) ($r['diasDaSemana'] ?? '')))) >= 7;
    }

    /**
     * O destino do Telium para a sequência de ações de uma regra.
     *
     * @param list<array{acao:string, cfg:array<string,string>}> $acoes
     * @param list<string> $avisos recebe o que não deu para migrar
     * @return array{0:string, 1:string}|null
     */
    private function resolver(array $acoes, string $nome, string $numero, array &$avisos): ?array
    {
        $acoes = array_values(array_filter($acoes, static fn ($a) => $a['acao'] !== 'ccustos'));
        if ($acoes === []) {
            $avisos[] = 'a regra não fazia nada além do centro de custo: desliga';

            return ['desligar', ''];
        }

        $a = $acoes[0];
        $resto = array_slice($acoes, 1);
        $cfg = $a['cfg'];

        switch ($a['acao']) {
            case 'ivr':
                return $this->criarUra($cfg, $nome, $avisos);

            case 'playback':
                $depois = $this->resolver($resto, $nome, $numero, $avisos) ?? ['desligar', ''];
                $audio = $this->audio((string) ($cfg['file'] ?? ''), $nome, 'anuncio');
                if ($audio === null) {
                    $avisos[] = "o áudio \"{$cfg['file']}\" tocava antes; envie-o em Áudios e ponha um anúncio no caminho";

                    return $depois;
                }

                return $this->criarAnuncio($nome, (int) $audio['id'], $depois, $avisos);

            case 'gocontext':
                $this->restoIgnorado($resto, $avisos);

                return ['personalizado', (string) $this->contexto((string) ($cfg['context'] ?? ''),
                                                                   (string) ($cfg['exten'] ?? 's'))];

            case 'discarramal':
                $ramal = preg_replace('/\D/', '', (string) (($cfg['ramal'] ?? '') !== '' ? $cfg['ramal'] : $numero)) ?? '';
                $ativo = $ramal === '' ? false : Bd::valor('SELECT ativo FROM ramais WHERE numero = ?', [$ramal]);
                if ($ativo === false) {
                    $avisos[] = "o ramal {$ramal} não existe no Telium";

                    return null;
                }
                // Apontar para ramal desativado é erro na conferência da
                // central — e a atualização para nele.
                if ((int) $ativo !== 1) {
                    $avisos[] = "o ramal {$ramal} está desativado: ative-o e aponte para ele à mão";

                    return null;
                }
                $this->restoIgnorado($resto, $avisos);

                return ['ramal', $ramal];

            case 'queue':
            case 'fila':
                $fila = (string) ($cfg['queue'] ?? $cfg['fila'] ?? '');
                if (!isset($this->filas[$fila])) {
                    $avisos[] = "a fila \"{$fila}\" não foi migrada";

                    return null;
                }
                if ((int) Bd::valor('SELECT ativo FROM filas WHERE numero = ?', [$this->filas[$fila]]) !== 1) {
                    $avisos[] = "a fila {$this->filas[$fila]} está desativada: ative-a e aponte para ela à mão";

                    return null;
                }
                $this->restoIgnorado($resto, $avisos);

                return ['fila', $this->filas[$fila]];

            // O número muda antes de seguir: troca inteira ("replace"),
            // prefixo ou sufixo. "hangup" no lugar do número é desligar.
            case 'rewrite':
                $troca = trim((string) ($cfg['replace'] ?? ''));
                if (strtolower($troca) === 'hangup') {
                    return ['desligar', ''];
                }
                $novo = $troca !== '' ? $troca : ((string) ($cfg['prefix'] ?? '') . $numero . (string) ($cfg['suffix'] ?? ''));

                return $this->resolver($resto, $nome, $novo, $avisos);

            // Encaminhar para fora: no Telium é o destino "número externo",
            // que sai pelas rotas de saída — e não pelo tronco que o SNEP
            // forçava.
            case 'discartronco':
                if (preg_match('/^[0-9*#+]{3,20}$/', $numero) !== 1) {
                    $avisos[] = "o número para onde o SNEP desviava ({$numero}) não é um número fixo";

                    return null;
                }
                $avisos[] = "desvia para {$numero} pelas rotas de saída (no SNEP, pelo tronco "
                          . ($cfg['tronco'] ?? '?') . ')';
                $this->restoIgnorado($resto, $avisos);

                return ['externo', $numero];

            case 'executeapp':
                $app = strtolower(trim((string) ($cfg['application'] ?? '')));
                if ($app === 'hangup') {
                    return ['desligar', ''];
                }
                $avisos[] = "a aplicação {$cfg['application']}({$cfg['parameters']}) do SNEP não tem equivalente migrado. "
                          . 'A regra fazia: ' . implode(' › ', array_map(static fn ($x) => $x['acao']
                              . ($x['cfg'] !== [] ? '(' . implode(', ', array_map(static fn ($k, $v) => "{$k}={$v}",
                                  array_keys($x['cfg']), $x['cfg'])) . ')' : ''), $acoes))
                          . '. Monte o equivalente num contexto do extensions_custom.conf e aponte para ele com um destino personalizado';

                return null;

            case 'hangup':
            case 'desligar':
                return ['desligar', ''];

            default:
                $avisos[] = "a ação \"{$a['acao']}\" do SNEP não tem equivalente migrado";

                return null;
        }
    }

    /** O que a regra ainda fazia depois do destino (outra fila, outro ramal) não migra. */
    private function restoIgnorado(array $resto, array &$avisos): void
    {
        if ($resto === []) {
            return;
        }
        $passos = array_map(static fn ($a) => $a['acao'] . (isset($a['cfg']['queue']) ? " {$a['cfg']['queue']}"
            : (isset($a['cfg']['ramal']) ? " {$a['cfg']['ramal']}" : '')), $resto);
        $avisos[] = 'depois disso o SNEP ainda tentava ' . implode(', ', $passos)
                  . ': no Telium, use o destino de quando não atende (do ramal ou da fila)';
    }

    private function criarUra(array $cfg, string $nome, array &$avisos): ?array
    {
        $nomeUra = mb_substr($nome, 0, 80);
        $existente = Bd::valor('SELECT id FROM ura WHERE nome = ?', [$nomeUra]);
        if ($existente !== false) {
            return ['ura', (string) $existente];
        }

        $saudacao = null;
        $arquivo = (string) ($cfg['file'] ?? '');
        if ($arquivo !== '') {
            $audio = $this->audio($arquivo, $nomeUra, 'ura');
            if ($audio !== null) {
                $anuncio = $this->criarAnuncio("Saudação {$nomeUra}", (int) $audio['id'], null, $avisos);
                $saudacao = $anuncio[1] ?? null;
            } else {
                $avisos[] = "a saudação \"{$arquivo}\" não está em Áudios: a URA ficou muda até você escolher o áudio nela";
            }
        }

        $ura = $this->criar('ura', array_filter([
            'nome' => $nomeUra,
            'anuncio_id' => $saudacao,
            'timeout_digito' => self::inteiro($cfg['timeout'] ?? null, 1),
            'tentativas' => self::inteiro($cfg['wloops'] ?? null, 1),
            'discagem_direta' => ($cfg['allow_exten_dial'] ?? 'false') === 'true' ? 1 : 0,
            'ativo' => 1,
        ], static fn ($v) => $v !== null));
        if ($ura['erro'] !== null) {
            $avisos[] = 'a URA não foi criada: ' . $ura['erro'];

            return null;
        }

        // Cada tecla é a regra cujo destino é <prefixo>-<tecla>.
        $prefixo = (string) ($cfg['prefix'] ?? '');
        foreach (self::teclas((string) ($cfg['digits'] ?? '')) as $i => $tecla) {
            $regra = $this->regraDoDestino(["{$prefixo}-{$tecla}", "{$prefixo}{$tecla}"]);
            if ($regra === null) {
                $avisos[] = "a tecla {$tecla} da URA não tinha regra no SNEP";
                continue;
            }
            $rotulo = mb_substr(trim((string) $regra['desc']) ?: "Opção {$tecla}", 0, 80);
            $destino = $this->resolver($this->acoes[(int) $regra['id']] ?? [], $rotulo, '', $avisos);
            if ($destino === null) {
                $avisos[] = "a tecla {$tecla} ({$rotulo}) ficou sem destino";
                continue;
            }
            $opcao = $this->criar('ura-opcoes', [
                'ura_id' => $ura['id'], 'tecla' => $tecla, 'rotulo' => $rotulo,
                'destino_tipo' => $destino[0], 'destino_valor' => $destino[1], 'ordem' => ($i + 1) * 10,
            ]);
            if ($opcao['erro'] !== null) {
                $avisos[] = "a tecla {$tecla}: {$opcao['erro']}";
            }
        }

        return ['ura', (string) $ura['id']];
    }

    /** @param array{0:string,1:string}|null $depois */
    private function criarAnuncio(string $nome, int $audioId, ?array $depois, array &$avisos): ?array
    {
        $nome = mb_substr($nome, 0, 80);
        $existente = Bd::valor('SELECT id FROM anuncios WHERE nome = ? AND audio_id = ?', [$nome, $audioId]);
        if ($existente !== false) {
            return ['anuncio', (string) $existente];
        }

        $r = $this->criar('anuncios', array_filter([
            'nome' => $nome,
            'descricao' => 'Migrado do SNEP',
            'audio_id' => $audioId,
            'destino_tipo' => $depois[0] ?? null,
            'destino_valor' => $depois[1] ?? null,
            'ativo' => 1,
        ], static fn ($v) => $v !== null));
        if ($r['erro'] !== null) {
            $avisos[] = "o anúncio {$nome} não foi criado: {$r['erro']}";

            return $depois;
        }

        return ['anuncio', (string) $r['id']];
    }

    /** O destino personalizado para um contexto do dialplan do SNEP. */
    private function contexto(string $contexto, string $extensao): int
    {
        $chave = "{$contexto},{$extensao}";
        if (isset($this->contextos[$chave])) {
            return $this->contextos[$chave];
        }

        $existente = Bd::valor(
            "SELECT id FROM destinos_personalizados WHERE contexto = ? AND extensao = ? AND prioridade = '1'",
            [$contexto, $extensao]
        );
        if ($existente !== false) {
            return $this->contextos[$chave] = (int) $existente;
        }

        $r = $this->criar('destinos-personalizados', [
            'nome' => mb_substr("SNEP: {$contexto}", 0, 80),
            'descricao' => 'Contexto do dialplan do SNEP. Copie-o para o extensions_custom.conf.',
            'contexto' => $contexto,
            'extensao' => $extensao,
            'prioridade' => '1',
            'ativo' => 1,
        ]);
        $this->linha('Contextos do SNEP', $contexto, $r['erro'] !== null ? 'erro' : 'aviso', $r['erro']
            ?? 'Criado como destino personalizado. O contexto era do dialplan do SNEP: copie-o para o '
             . 'extensions_custom.conf desta central, ou a chamada para nele.');

        return $this->contextos[$chave] = (int) ($r['id'] ?? 0);
    }

    private function regraDoDestino(array $valores): ?array
    {
        foreach ($this->regras as $r) {
            foreach (array_map('trim', explode(',', (string) $r['destino'])) as $d) {
                if (preg_match('/^RX?:(.+)$/', $d, $m) === 1 && in_array($m[1], $valores, true)) {
                    return $r;
                }
            }
        }

        return null;
    }

    private function descrever(array $destino): string
    {
        return match ($destino[0]) {
            'ura' => 'a URA ' . (string) Bd::valor('SELECT nome FROM ura WHERE id = ?', [$destino[1]]),
            'anuncio' => 'o anúncio ' . (string) Bd::valor('SELECT nome FROM anuncios WHERE id = ?', [$destino[1]]),
            'personalizado' => 'o contexto '
                . (string) Bd::valor('SELECT contexto FROM destinos_personalizados WHERE id = ?', [$destino[1]]),
            'ramal' => "o ramal {$destino[1]}",
            'fila' => "a fila {$destino[1]}",
            'externo' => "o número externo {$destino[1]}",
            'condicao' => 'a condição horária '
                . (string) Bd::valor('SELECT nome FROM condicoes_horarias WHERE id = ?', [$destino[1]]),
            'desligar' => 'desligar',
            default => $destino[0],
        };
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /** @var list<array<string,?string>> */
    private array $regras = [];
    /** @var array<string,int> quantas regras do SNEP têm cada descrição */
    private array $descricoes = [];

    /**
     * O nome de uma regra no Telium. Duas regras do SNEP com a mesma
     * descrição (comum: "Celular" do NOC e "Celular" geral) virariam a
     * mesma rota, URA ou condição — a segunda tomada pela primeira como
     * "já existe". Repetida, a descrição leva o número da regra; o nome
     * sai igual a cada execução, e rodar de novo continua achando o item.
     */
    private function nomeDaRegra(array $r, int $max = 80): string
    {
        $desc = trim((string) $r['desc']);
        if ($desc === '') {
            return "Regra {$r['id']}";
        }
        $sufixo = ($this->descricoes[mb_strtolower($desc)] ?? 1) > 1 ? " (#{$r['id']})" : '';

        return mb_substr($desc, 0, $max - mb_strlen($sufixo)) . $sufixo;
    }

    private function indexar(array $snep): void
    {
        $cfg = [];
        foreach ($snep['regras_negocio_actions_config'] ?? [] as $c) {
            $cfg[(int) $c['regra_id']][(int) $c['prio']][(string) $c['key']] = (string) $c['value'];
        }
        $acoes = $snep['regras_negocio_actions'] ?? [];
        usort($acoes, static fn ($a, $b) => [(int) $a['regra_id'], (int) $a['prio']] <=> [(int) $b['regra_id'], (int) $b['prio']]);
        foreach ($acoes as $a) {
            $nome = strtolower((string) preg_replace('/^PBX_Rule_Action_/i', '', (string) $a['action']));
            $this->acoes[(int) $a['regra_id']][] = [
                'acao' => $nome,
                'cfg' => $cfg[(int) $a['regra_id']][(int) $a['prio']] ?? [],
            ];
        }

        foreach ($snep['date_alias_list'] ?? [] as $d) {
            $this->datas[(int) $d['dateid']][] = $d;
        }

        foreach ($snep['expr_alias'] ?? [] as $a) {
            $this->alias[(int) $a['aliasid']] = ['nome' => (string) $a['name'], 'expressoes' => []];
        }
        foreach ($snep['expr_alias_expression'] ?? [] as $e) {
            $this->alias[(int) $e['aliasid']]['expressoes'][] = trim((string) $e['expression']);
        }
    }

    /** @return list<array<string,?string>> */
    private function regrasEmOrdem(array $regras): array
    {
        usort($regras, static fn ($a, $b) => [(int) $b['prio'], (int) $a['id']] <=> [(int) $a['prio'], (int) $b['id']]);
        foreach ($regras as $r) {
            $chave = mb_strtolower(trim((string) $r['desc']));
            $this->descricoes[$chave] = ($this->descricoes[$chave] ?? 0) + 1;
        }

        return $this->regras = $regras;
    }

    /**
     * O áudio do Telium com o nome do arquivo do SNEP, se já foi enviado.
     *
     * @return array<string,mixed>|null
     */
    private function audio(string $arquivoSnep, string $usadoEm, string $categoria): ?array
    {
        $nome = (string) preg_replace('/\.(wav|gsm|sln|ulaw|alaw|mp3|ogg)$/i', '', basename($arquivoSnep));
        if ($nome === '') {
            return null;
        }
        $base = Audios::nomeBase($nome);
        $achado = Bd::um('SELECT id, arquivo FROM audios WHERE arquivo = ? OR nome = ? ORDER BY arquivo = ? DESC LIMIT 1',
                         [$base, $nome, $base]);
        $origem = $achado !== null ? 'telium' : null;

        // Veio no pacote do SNEP: converte e cadastra em Áudios.
        $arquivo = $achado === null ? $this->somDoPacote($nome) : null;
        if ($arquivo !== null) {
            $achado = $this->anotar(Audios::importar($arquivo, $nome, $categoria, $this->usuarioId(), !$this->simular));
            $origem = $achado !== null ? 'pacote' : 'falhou';
        }

        $this->audios[$nome] ??= ['snep' => $nome, 'telium' => $base, 'categoria' => $categoria,
                                  'achado' => $achado !== null, 'origem' => $origem, 'usado_em' => []];
        $this->audios[$nome]['usado_em'][] = $usadoEm;

        return $achado;
    }

    /** O arquivo do pacote com este nome (sem extensão), se veio. */
    private function somDoPacote(string $nome): ?string
    {
        // O pacote guarda o arquivo com o nome limpo ("Boas Vindas" vira
        // "Boas_Vindas"): a busca limpa do mesmo jeito.
        $sons = $this->pacote['sons'] ?? [];
        $limpo = SnepPacote::limpo($nome);
        foreach ([$nome, $limpo] as $n) {
            if (isset($sons[$n])) {
                return $sons[$n];
            }
        }
        foreach ($sons as $chave => $caminho) {
            if (strcasecmp((string) $chave, $nome) === 0 || strcasecmp((string) $chave, $limpo) === 0) {
                return $caminho;
            }
        }

        return null;
    }

    /**
     * A música de espera de uma classe do SNEP, do pacote. A classe aponta
     * para uma pasta (musiconhold.conf); o Telium toca um arquivo por
     * classe, então várias faixas viram uma só, na ordem da pasta.
     *
     * @return array{id:int, arquivo:string}|null
     */
    private function musicaDoPacote(string $classe): ?array
    {
        $moh = $this->pacote['moh'] ?? [];
        if ($moh === []) {
            return null;
        }
        $pasta = SnepPacote::limpo(SnepPacote::classesDeMusica($this->pacote['conf'] ?? [])[$classe] ?? $classe);
        $arquivos = $moh[$pasta] ?? null;
        if ($arquivos === null) {
            // Sem o .conf, a pasta costuma ser o começo do nome da classe
            // (MesterRaw => MesterRawEspera20251212).
            foreach ($moh as $nome => $lista) {
                if ($nome !== '_raiz' && (stripos($classe, (string) $nome) === 0 || stripos((string) $nome, $classe) === 0)) {
                    $arquivos = $lista;
                    break;
                }
            }
        }
        if ($arquivos === null || $arquivos === []) {
            return null;
        }

        $origem = $arquivos[0];
        if (count($arquivos) > 1 && !$this->simular) {
            $junto = dirname($arquivos[0]) . '/_junto.wav';
            // As faixas precisam ter o mesmo formato para o sox juntar; se
            // não tiverem, fica a primeira.
            $comando = 'sox ' . implode(' ', array_map('escapeshellarg', $arquivos))
                     . ' -r 8000 -c 1 ' . escapeshellarg($junto) . ' 2>&1';
            exec($comando, $saida, $rc);
            if ($rc === 0 && is_file($junto)) {
                $origem = $junto;      // fica na pasta de trabalho, que some com o envio
            }
        }
        $audio = $this->anotar(Audios::importar($origem, $classe, 'espera', $this->usuarioId(), !$this->simular));
        $this->audios[$classe] = ['snep' => $classe, 'telium' => Audios::nomeBase($classe), 'categoria' => 'espera',
                                  'achado' => $audio !== null, 'origem' => $audio !== null ? 'pacote' : 'falhou',
                                  'usado_em' => ['música de espera (' . count($arquivos) . ' faixa(s))']];

        return $audio;
    }

    /** Banco desfeito: os áudios convertidos nesta importação saem do disco. */
    private function apagarGravados(): void
    {
        foreach ($this->gravados as $base) {
            Audios::apagarArquivos($base);
        }
        $this->gravados = [];
    }

    /** Guarda o áudio que esta importação gravou no disco. */
    private function anotar(?array $audio): ?array
    {
        if ($audio !== null && ($audio['novo'] ?? false) && !$this->simular) {
            $this->gravados[] = (string) $audio['arquivo'];
        }

        return $audio;
    }

    private function usuarioId(): ?int
    {
        $id = $this->req->getAttribute('usuario')['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Cria pelo Recurso, como a tela faria.
     *
     * @return array{id:int, erro:?string}
     */
    private function criar(string $recurso, array $dados): array
    {
        $resposta = $this->recursos[$recurso]->criar($this->req->withParsedBody($dados), new SlimResponse());
        if ($resposta->getStatusCode() >= 400) {
            return ['id' => 0, 'erro' => self::erroDe($resposta)];
        }
        $corpo = json_decode((string) $resposta->getBody(), true) ?: [];

        return ['id' => (int) ($corpo['id'] ?? 0), 'erro' => null];
    }

    private static function erroDe(\Psr\Http\Message\ResponseInterface $resposta): string
    {
        $corpo = json_decode((string) $resposta->getBody(), true) ?: [];
        // O cadastro de agente devolve um erro por campo, em "campos".
        if (is_array($corpo['campos'] ?? null) && $corpo['campos'] !== []) {
            return implode('; ', array_map(static fn ($c, $m) => "{$c}: {$m}",
                                           array_keys($corpo['campos']), $corpo['campos']));
        }
        $campo = (string) ($corpo['campo'] ?? '');

        return ($campo !== '' ? "{$campo}: " : '') . (string) ($corpo['erro'] ?? 'recusado');
    }

    /**
     * Os erros da conferência do cadastro (os que param a atualização),
     * como texto: "onde: o que".
     *
     * @return list<string>
     */
    private static function errosDaCentral(): array
    {
        return array_values(array_map(
            static fn (array $p): string => "{$p['onde']}: {$p['texto']}",
            array_filter(\Telium\Gerador\Conferencia::problemas(), static fn (array $p): bool => $p['nivel'] === 'erro')
        ));
    }

    private function linha(string $grupo, string $item, string $acao, string $mensagem): void
    {
        $this->linhas[] = [
            'grupo' => $grupo, 'item' => $item, 'acao' => $acao, 'mensagem' => $mensagem,
            // A chave com que a tela tira (ou devolve) o item da importação.
            // Só o que vai ser criado — e o que já foi tirado — se escolhe.
            'chave' => in_array($grupo, self::ESCOLHIVEIS, true)
                       && in_array($acao, ['criar', 'aviso', 'erro', 'pulado'], true) ? "{$grupo}|{$item}" : null,
        ];
    }

    /** A pessoa tirou este item da importação? Então ele fica de fora, e diz. */
    private function pulou(string $grupo, string $item): bool
    {
        if (!isset($this->ignorar["{$grupo}|{$item}"])) {
            return false;
        }
        $this->linha($grupo, $item, 'pulado', 'Fora da importação: desmarcado na prévia. O que dependia dele aparece com aviso ou erro.');

        return true;
    }

    /** @param list<array{acao:string, cfg:array<string,string>}> $acoes */
    private static function acao(array $acoes, string $nome): ?array
    {
        foreach ($acoes as $a) {
            if ($a['acao'] === $nome) {
                return $a;
            }
        }

        return null;
    }

    /** "SAC - FAQ<5555>", "MBilling(112643379)" => "SAC - FAQ", "MBilling" */
    private static function nomeDoCallerid(string $callerid): string
    {
        return trim((string) preg_replace('/\s*[<(][^>)]*[>)]\s*/', ' ', $callerid));
    }

    private static function codecs(string $allow): ?string
    {
        $lista = array_values(array_intersect(
            array_map('trim', preg_split('/[;,]/', strtolower($allow)) ?: []),
            self::CODECS
        ));

        return $lista === [] ? null : implode(',', $lista);
    }

    private static function inteiro(?string $valor, int $minimo): ?int
    {
        return $valor !== null && ctype_digit(trim($valor)) && (int) $valor >= $minimo ? (int) $valor : null;
    }

    private static function vazia(?string $valor): ?string
    {
        return match (strtolower(trim((string) $valor))) {
            'yes', '1', 'true' => 'sim',
            'no', '0', 'false' => 'nao',
            'strict' => 'estrito',
            default => null,
        };
    }

    /** "0,1,2" ou "012" => ["0","1","2"] */
    private static function teclas(string $digitos): array
    {
        return array_values(array_filter(
            str_contains($digitos, ',') ? array_map('trim', explode(',', $digitos)) : str_split($digitos),
            static fn ($t) => preg_match('/^[0-9*#]$/', $t) === 1
        ));
    }

    /**
     * A classe da rota pelo nome que o SNEP dava a ela. A classe decide a
     * permissão de discagem do ramal: rota que junta fixo e celular fica
     * com a mais aberta (local), como era no SNEP, onde todos discavam.
     */
    private static function classe(string $nome): string
    {
        $n = mb_strtolower($nome);

        return match (true) {
            str_contains($n, 'emerg') => 'emergencia',
            (bool) preg_match('/ldi|ddi|internac/', $n) => 'ddi',
            (bool) preg_match('/ldn|ddd|interurb/', $n) => 'ddd',
            (bool) preg_match('/celular|m[oó]vel/', $n) && !str_contains($n, 'fixo') => 'celular',
            (bool) preg_match('/servi|0800|especia|[uú]teis/', $n) => 'especial',
            default => 'local',
        };
    }

    private static function senhaForte(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $senha = '';
        for ($i = 0; $i < 20; $i++) {
            $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $senha;
    }
}
