<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Telium\Http\Controllers\Audios;
use Telium\Http\Controllers\Filas;
use Telium\Http\Recurso;
use Telium\Suporte\Bd;

/**
 * Migração do SNEP 3: troncos, ramais, filas, regras de saída e regras de
 * entrada (com as URAs, anúncios e contextos que elas usam).
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
 * Áudios não vêm no dump. Um áudio do SNEP só entra numa URA, anúncio ou
 * fila se já estiver em Gravações › Áudios com o mesmo nome; senão, o
 * relatório avisa. Criar o anúncio sem o arquivo deixaria o dialplan
 * tocando o que não existe — e o teste da atualização pararia a central.
 */
final class MigracaoSnep
{
    private const CODECS = ['ulaw', 'alaw', 'gsm', 'g722', 'opus', 'g729'];
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
    /** @var array<int, list<array{acao:string, cfg:array<string,string>}>> */
    private array $acoes = [];
    /** @var array<int, array{nome:string, expressoes:list<string>}> */
    private array $alias = [];
    private bool $simular = true;

    /** @param array<string, Recurso> $recursos */
    public function __construct(private readonly array $recursos, private readonly Request $req)
    {
    }

    /**
     * @param array<string, list<array<string,?string>>> $snep o que SnepDump::ler devolveu
     * @param array{fila_inicio?: string} $opcoes
     * @return array<string,mixed>
     */
    public function executar(array $snep, array $opcoes, bool $simular): array
    {
        $this->simular = $simular;
        $this->indexar($snep);

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            $this->migrarTroncos($snep['trunks'] ?? []);
            $this->migrarRamais($snep['peers'] ?? []);
            $this->migrarFilas($snep['queues'] ?? [], $snep['queue_members'] ?? [],
                               (string) ($opcoes['fila_inicio'] ?? '8000'));
            $regras = $this->regrasEmOrdem($snep['regras_negocio'] ?? []);
            $this->migrarSaida($regras);
            $this->migrarEntrada($regras);

            if ($simular) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $resumo = [];
        foreach ($this->linhas as $l) {
            $resumo[$l['acao']] = ($resumo[$l['acao']] ?? 0) + 1;
        }

        return [
            'simulado' => $simular,
            'resumo' => $resumo,
            'linhas' => $this->linhas,
            'audios' => array_values($this->audios),
            'contextos' => array_keys($this->contextos),
            'senhas' => $simular ? [] : $this->senhas,
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
            $tecnologia = strtoupper((string) ($t['technology'] ?: $t['type']));

            if ($tecnologia !== 'SIP') {
                $this->linha('Troncos', $item, 'erro',
                    "Tronco {$tecnologia}: esta central entronca só por SIP. Cadastre o substituto à mão.");
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

            $email = trim((string) ($p['email'] ?? ''));
            $r = $this->criar('ramais', array_filter([
                'numero' => $numero,
                'nome' => $nome,
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
            $this->linha('Ramais', $item, 'criar', $gerada
                ? 'Senha SIP nova (a do SNEP tinha menos de 12 caracteres): reconfigure o telefone.'
                : 'Mesma senha SIP do SNEP: o telefone registra sem reconfigurar.');
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

            $existente = Bd::valor('SELECT numero FROM filas WHERE nome = ?', [$nome]);
            if ($existente !== false) {
                $this->filas[(string) $q['name']] = (string) $existente;
                $this->linha('Filas', $nome, 'existe', "Já existe uma fila com este nome (número {$existente}); não foi alterada.");
                continue;
            }

            // O SNEP chama a fila pelo nome; no Telium ela tem número.
            while ($this->numeroOcupado((string) $proximo)) {
                $proximo++;
            }
            $numero = (string) $proximo++;
            $avisos = [];

            $musica = trim((string) ($q['musiconhold'] ?? ''));
            $classe = 'default';
            if ($musica !== '' && $musica !== 'default') {
                $audio = $this->audio($musica, "música de espera da fila {$nome}", 'espera');
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

            $estrategia = strtolower((string) ($q['strategy'] ?? ''));
            $r = $this->criar('filas', array_filter([
                'numero' => $numero,
                'nome' => $nome,
                'descricao' => 'Migrada do SNEP',
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

            // Os membros fixos da fila: no SNEP, a interface do ramal.
            $agentes = [];
            foreach ($membros as $m) {
                if ((string) $m['queue_name'] !== (string) $q['name']) {
                    continue;
                }
                $interface = (string) $m['interface'];
                if (preg_match('#^(?:SIP|PJSIP|IAX2)/(\d+)$|^Local/(\d+)@#', $interface, $x) !== 1) {
                    $avisos[] = "membro {$interface} não é um ramal (agente do SNEP? use o Call Center)";
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

            $this->linha('Filas', $nome, $avisos === [] ? 'criar' : 'aviso',
                "Número {$numero}, " . count($agentes) . ' membro(s).'
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

    // ------------------------------------------------------------------
    // Regras de saída
    // ------------------------------------------------------------------

    private function migrarSaida(array $regras): void
    {
        $ordem = 0;
        foreach ($regras as $r) {
            $acoes = $this->acoes[(int) $r['id']] ?? [];
            $discar = self::acao($acoes, 'discartronco');
            if ($discar === null || ($r['type'] ?? '') === 'incoming') {
                continue;
            }
            $nome = mb_substr(trim((string) $r['desc']) ?: "Regra {$r['id']}", 0, 80);
            $ordem += 10;

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

            $avisos = $this->avisosDeValidade($r);
            $adicionar = '';
            foreach ($acoes as $a) {
                if ($a['acao'] === 'discartronco') {
                    break;
                }
                if ($a['acao'] === 'rewrite') {
                    $adicionar .= preg_replace('/\D/', '', (string) ($a['cfg']['prefix'] ?? '')) ?? '';
                    $corte = (string) ($a['cfg']['cut'] ?? 'nocut');
                    if (($corte !== '' && $corte !== 'nocut') || ($a['cfg']['replace'] ?? '') !== ''
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
                $padroes[] = ['padrao' => $remover . $resto, 'prefixo_remover' => $remover,
                              'prefixo_adicionar' => $adicionar];
            }
            if ($padroes === []) {
                $this->linha('Regras de saída', $nome, 'erro', "O destino \"{$r['destino']}\" não tem padrão de número.");
                continue;
            }
            if (!in_array(trim((string) $r['origem']), ['G:all', 'X', ''], true)) {
                $avisos[] = "no SNEP valia só para a origem {$r['origem']}; aqui vale para todos os ramais "
                          . '(restrinja pelas permissões de discagem do ramal)';
            }

            $rota = $this->criar('rotas-saida', [
                'nome' => $nome,
                'ordem' => $ordem,
                'tronco_id' => $tronco,
                'classe' => self::classe($nome . ' ' . $this->nomesDeAlias((string) $r['destino'])),
                'ativo' => (int) $r['ativa'] === 1 ? 1 : 0,
                'padroes' => $padroes,
            ]);
            if ($rota['erro'] !== null) {
                $this->linha('Regras de saída', $nome, 'erro', $rota['erro']);
                continue;
            }
            $this->linha('Regras de saída', $nome, $avisos === [] ? 'criar' : 'aviso',
                'Padrões: ' . implode(', ', array_map(
                    static fn ($p) => $p['padrao'] . ($p['prefixo_remover'] !== '' ? " (tira {$p['prefixo_remover']})" : '')
                        . ($p['prefixo_adicionar'] !== '' ? " (põe {$p['prefixo_adicionar']})" : ''),
                    $padroes
                )) . '.' . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.'));
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

        foreach ($regras as $r) {
            $acoes = $this->acoes[(int) $r['id']] ?? [];
            if (self::acao($acoes, 'discartronco') !== null && ($r['type'] ?? '') !== 'incoming') {
                continue;      // é de saída
            }
            $nome = mb_substr(trim((string) $r['desc']) ?: "Regra {$r['id']}", 0, 120);
            $destinos = array_values(array_filter(array_map('trim', explode(',', (string) $r['destino']))));

            if (count($destinos) === 1 && preg_match('/^RX?:(.+)$/', $destinos[0], $m) === 1 && isset($opcoes[$m[1]])) {
                continue;      // vira entrada da URA, junto com ela
            }
            if (self::acao($acoes, 'discarramal') !== null && array_diff($destinos, ['G:all', 'X']) === []) {
                $this->linha('Regras de entrada', $nome, 'ignorado',
                    'Ligação de ramal para ramal: o Telium já faz, sem regra.');
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
                if (preg_match('/^RX?:(.+)$/', $o, $m) === 1) {
                    $cid = str_replace('|', '', $m[1]);
                }
            }
            $avisos = $this->avisosDeValidade($r);

            $destino = $this->resolver($acoes, $nome, $dids[0], $avisos);
            if ($destino === null) {
                $this->linha('Regras de entrada', $nome, 'erro', ucfirst(implode('; ', $avisos)) . '.');
                continue;
            }

            foreach ($dids as $did) {
                $item = "{$nome} ({$did})";
                if (Bd::valor('SELECT id FROM rotas_entrada WHERE did = ? AND cid_origem = ?',
                              [\Telium\Gerador\Padrao::normalizar($did), $cid]) !== false) {
                    $this->linha('Regras de entrada', $item, 'existe', 'Já existe uma rota para este número; não foi alterada.');
                    continue;
                }
                $rota = $this->criar('rotas-entrada', [
                    'did' => $did,
                    'descricao' => $nome,
                    'cid_origem' => $cid,
                    'destino_tipo' => $destino[0],
                    'destino_valor' => $destino[1],
                    'gravar' => (int) $r['record'] === 1 ? 1 : 0,
                    'ordem' => 10 + (int) $r['prio'],
                    'ativo' => (int) $r['ativa'] === 1 ? 1 : 0,
                ]);
                $this->linha('Regras de entrada', $item, $rota['erro'] !== null ? 'erro' : ($avisos === [] ? 'criar' : 'aviso'),
                    $rota['erro'] ?? ("Vai para {$this->descrever($destino)}." . ($avisos === [] ? '' : ' ' . ucfirst(implode('; ', $avisos)) . '.')));
            }
        }
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
                return ['personalizado', (string) $this->contexto((string) ($cfg['context'] ?? ''),
                                                                   (string) ($cfg['exten'] ?? 's'))];

            case 'discarramal':
                $ramal = preg_replace('/\D/', '', (string) ($cfg['ramal'] ?? $numero)) ?? '';
                if ($ramal === '' || Bd::valor('SELECT id FROM ramais WHERE numero = ?', [$ramal]) === false) {
                    $avisos[] = "o ramal {$ramal} não existe no Telium";

                    return null;
                }

                return ['ramal', $ramal];

            case 'queue':
            case 'fila':
                $fila = (string) ($cfg['queue'] ?? $cfg['fila'] ?? '');
                if (!isset($this->filas[$fila])) {
                    $avisos[] = "a fila \"{$fila}\" não foi migrada";

                    return null;
                }

                return ['fila', $this->filas[$fila]];

            case 'hangup':
            case 'desligar':
                return ['desligar', ''];

            default:
                $avisos[] = "a ação \"{$a['acao']}\" do SNEP não tem equivalente migrado";

                return null;
        }
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
            default => $destino[0],
        };
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /** @var list<array<string,?string>> */
    private array $regras = [];

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
        usort($regras, static fn ($a, $b) => [(int) $a['prio'], (int) $a['id']] <=> [(int) $b['prio'], (int) $b['id']]);

        return $this->regras = $regras;
    }

    /** @return list<string> */
    private function avisosDeValidade(array $r): array
    {
        $avisos = [];
        $dias = array_filter(explode(',', (string) ($r['diasDaSemana'] ?? '')));
        if (trim((string) ($r['validade'] ?? '')) !== '00:00:00-23:59:59' || count($dias) < 7) {
            $avisos[] = "no SNEP valia só em {$r['validade']} ({$r['diasDaSemana']}); aqui vale sempre — "
                      . 'use uma condição horária se precisar';
        }

        return $avisos;
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

        $this->audios[$nome] ??= ['snep' => $nome, 'telium' => $base, 'categoria' => $categoria,
                                  'achado' => $achado !== null, 'usado_em' => []];
        $this->audios[$nome]['usado_em'][] = $usadoEm;

        return $achado;
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
        $campo = (string) ($corpo['campo'] ?? '');

        return ($campo !== '' ? "{$campo}: " : '') . (string) ($corpo['erro'] ?? 'recusado');
    }

    private function linha(string $grupo, string $item, string $acao, string $mensagem): void
    {
        $this->linhas[] = ['grupo' => $grupo, 'item' => $item, 'acao' => $acao, 'mensagem' => $mensagem];
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

    /** A classe da rota pelo nome que o SNEP dava a ela. */
    private static function classe(string $nome): string
    {
        $n = mb_strtolower($nome);

        return match (true) {
            str_contains($n, 'emerg') => 'emergencia',
            (bool) preg_match('/ldi|ddi|internac/', $n) => 'ddi',
            (bool) preg_match('/ldn|ddd|interurb/', $n) => 'ddd',
            (bool) preg_match('/celular|m[oó]vel/', $n) => 'celular',
            (bool) preg_match('/servi|0800|especia/', $n) => 'especial',
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
