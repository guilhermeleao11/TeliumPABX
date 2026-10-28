<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Http\Controllers\Gravacoes;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

/**
 * Integração com o Kommo (CRM).
 *
 * O clique para ligar vem do app instalado no Kommo e passa pela mesma
 * porta do click to call (Http\Controllers\Kommo). Esta classe cuida do
 * caminho de volta: lê o CDR, junta as linhas de cada ligação e manda o
 * registro para o histórico do contato no Kommo, com o link da gravação.
 *
 * O envio roda fora da chamada, pelo temporizador telium-kommo (a cada
 * minuto): um Kommo lento ou fora do ar atrasa o registro, nunca o
 * atendimento. A ligação só é lida depois de terminada há um tempo, para
 * pegar a fila, a transferência e o arquivo de gravação já fechado.
 */
final class Kommo
{
    /** O nome que aparece no Kommo como origem da ligação. */
    public const ORIGEM = 'Telium PABX';

    /** Quanto esperar depois do fim da chamada antes de mandar. */
    private const ESPERA_SEGUNDOS = 90;

    /** Chamadas por passada do temporizador: o Kommo aceita 7 pedidos por segundo. */
    private const POR_PASSADA = 60;

    /** Erros seguidos de uma chamada antes de desistir dela. */
    private const MAX_TENTATIVAS = 5;

    /** @return array<string,mixed> a configuração (linha única) */
    public static function config(): array
    {
        return Bd::um('SELECT * FROM kommo_config WHERE id = 1') ?? ['id' => 1, 'ativo' => 0];
    }

    /**
     * O endereço da conta: "empresa", "empresa.kommo.com" ou o domínio
     * antigo do amoCRM. Só esses: o token vai junto em cada pedido, e um
     * domínio qualquer aqui o entregaria a quem fosse dono dele.
     */
    public static function host(?string $subdominio): ?string
    {
        $s = strtolower(trim((string) $subdominio));
        $s = preg_replace('#^https?://#', '', $s) ?? '';
        $s = rtrim(explode('/', $s)[0], '.');
        if ($s === '') {
            return null;
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $s) === 1) {
            return "{$s}.kommo.com";
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.(kommo\.com|amocrm\.com|amocrm\.ru)$/', $s) === 1) {
            return $s;
        }

        return null;
    }

    /** O começo dos links que o navegador de quem usa o Kommo abre (as gravações). */
    public static function urlPublica(array $cfg): string
    {
        $url = trim((string) ($cfg['url_publica'] ?? ''));
        if ($url === '') {
            $url = (string) Ambiente::get('APP_URL', '');
        }

        return rtrim($url, '/');
    }

    // ------------------------------------------------------------------
    // A API do Kommo
    // ------------------------------------------------------------------

    /**
     * Um pedido à API do Kommo.
     *
     * @return array{status:int, json:mixed, erro:string}
     */
    public static function api(array $cfg, string $metodo, string $caminho, ?array $corpo = null): array
    {
        $host = self::host($cfg['subdominio'] ?? null);
        $token = trim((string) ($cfg['token'] ?? ''));
        if ($host === null || $token === '') {
            return ['status' => 0, 'json' => null, 'erro' => 'Informe o endereço da conta e o token do Kommo.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'json' => null, 'erro' => 'O PHP deste servidor está sem a extensão curl.'];
        }

        // KOMMO_API_URL só existe na bancada, apontando para um Kommo de
        // mentira: o .env do servidor não tem esta linha.
        $base = rtrim((string) (Ambiente::get('KOMMO_API_URL', '') ?: "https://{$host}"), '/');
        $h = curl_init($base . $caminho);
        $opcoes = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json',
                                       'User-Agent: Telium-PABX'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($h, $opcoes);
        $texto = curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($h);
        curl_close($h);

        $json = is_string($texto) && $texto !== '' ? json_decode($texto, true) : null;
        if ($erro === '' && ($status < 200 || $status >= 300)) {
            $erro = self::explicar($status, $json);
        }

        return ['status' => $status, 'json' => $json, 'erro' => $erro];
    }

    /** O motivo de uma resposta de erro do Kommo, em português. */
    private static function explicar(int $status, mixed $json): string
    {
        $detalhe = is_array($json) ? (string) ($json['detail'] ?? $json['title'] ?? '') : '';
        if (is_array($json) && isset($json['validation-errors'])) {
            $detalhe .= ' ' . json_encode($json['validation-errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $base = match ($status) {
            401 => 'O Kommo recusou o token (vencido, revogado ou de outra conta)',
            402 => 'A conta do Kommo está sem assinatura ativa',
            403 => 'O token não tem permissão para isto no Kommo',
            404 => 'Endereço não encontrado no Kommo (confira a conta)',
            429 => 'O Kommo pediu para diminuir o ritmo (limite de pedidos)',
            default => "O Kommo respondeu HTTP {$status}",
        };

        return mb_substr(trim($base . ($detalhe !== '' ? ': ' . $detalhe : '')), 0, 250);
    }

    /** @return array{ok:bool, detalhe:string} a conta responde com este token? */
    public static function testar(array $cfg): array
    {
        $r = self::api($cfg, 'GET', '/api/v4/account');
        if ($r['erro'] !== '') {
            return ['ok' => false, 'detalhe' => $r['erro']];
        }

        return ['ok' => true, 'detalhe' => 'Conectado à conta ' . (string) ($r['json']['name'] ?? $cfg['subdominio']) . '.'];
    }

    /**
     * Traz os usuários do Kommo, sem perder o ramal de quem já tinha um.
     * Quem saiu do Kommo fica desativado, não apagado: o histórico de
     * chamadas aponta para ele.
     *
     * @return array{ok:bool, detalhe:string, total:int}
     */
    public static function sincronizarUsuarios(array $cfg): array
    {
        $vistos = [];
        for ($pagina = 1; $pagina <= 20; $pagina++) {
            $r = self::api($cfg, 'GET', "/api/v4/users?limit=250&page={$pagina}");
            if ($r['status'] === 204) {
                break;
            }
            if ($r['erro'] !== '') {
                return ['ok' => false, 'detalhe' => $r['erro'], 'total' => count($vistos)];
            }
            $lista = (array) ($r['json']['_embedded']['users'] ?? []);
            foreach ($lista as $u) {
                $id = (int) ($u['id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $vistos[] = $id;
                Bd::executar(
                    'INSERT INTO kommo_usuarios (kommo_id, nome, email, ativo) VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE nome = VALUES(nome), email = VALUES(email), ativo = 1',
                    [$id, mb_substr((string) ($u['name'] ?? "Usuário {$id}"), 0, 120),
                     mb_substr((string) ($u['email'] ?? ''), 0, 160) ?: null]
                );
            }
            if (!isset($r['json']['_links']['next'])) {
                break;
            }
        }

        if ($vistos !== []) {
            $marcas = implode(',', array_fill(0, count($vistos), '?'));
            Bd::executar("UPDATE kommo_usuarios SET ativo = 0 WHERE kommo_id NOT IN ({$marcas})", $vistos);
        }

        return ['ok' => true, 'detalhe' => count($vistos) . ' usuário(s) do Kommo.', 'total' => count($vistos)];
    }

    // ------------------------------------------------------------------
    // Do CDR ao registro
    // ------------------------------------------------------------------

    /**
     * O número como o Kommo o procura nos contatos: com +55 e DDD.
     *
     * O que chega do tronco e o que o ramal discou vêm de muitos jeitos:
     * 11999990000, 011999990000, 0 15 11 99999 0000 (com operadora),
     * 5511999990000, 99990000 (local). O Kommo guarda o contato como a
     * pessoa digitou, e procura pelos dígitos: com o número completo ele
     * acha o contato salvo de qualquer um desses jeitos.
     */
    public static function telefone(string $numero, ?string $ddd = null): string
    {
        $d = preg_replace('/\D/', '', $numero) ?? '';
        if ($d === '') {
            return '';
        }
        $comZero = str_starts_with($d, '0');
        $d = ltrim($d, '0');
        $n = strlen($d);

        if (str_starts_with($d, '55') && ($n === 12 || $n === 13)) {
            return '+' . $d;                       // 55 + DDD + número
        }
        if ($n === 10 || $n === 11) {
            return '+55' . $d;                     // DDD + número
        }
        if ($comZero && ($n === 12 || $n === 13)) {
            return '+55' . substr($d, 2);          // 0 + operadora + DDD + número
        }
        if (($n === 8 || $n === 9) && $ddd !== null && preg_match('/^[1-9][0-9]$/', $ddd) === 1) {
            return '+55' . $ddd . $d;              // local: o DDD da central
        }
        if ($n > 11) {
            return '+' . $d;                       // internacional
        }

        return $d;                                 // 0800, serviço, curto: como veio
    }

    /** O ramal num nome de canal (PJSIP/1001-0000000a, Local/1001@interno-…), ou ''. */
    public static function ramalDoCanal(string $canal): string
    {
        return preg_match('#^(?:PJSIP|SIP|Local)/(\d{2,10})[-@]#', $canal, $m) === 1 ? $m[1] : '';
    }

    /**
     * Uma ligação, a partir das linhas do CDR que têm o mesmo linkedid.
     *
     * @param list<array<string,mixed>> $linhas
     * @param array<string,true>        $ramais os números de ramal que existem
     * @return array<string,mixed>|null null: interna ou sem número externo
     */
    public static function montar(array $linhas, array $ramais, ?string $ddd): ?array
    {
        $entrada = null;
        $saida = null;
        foreach ($linhas as $l) {
            if (($l['direcao'] ?? '') === 'entrada' && $entrada === null) {
                $entrada = $l;
            }
            if (($l['direcao'] ?? '') === 'saida' && $saida === null) {
                $saida = $l;
            }
        }
        // Entrada que saiu de novo (siga-me para o celular) é de entrada:
        // é o cliente que ligou.
        $base = $entrada ?? $saida;
        if ($base === null) {
            return null;
        }
        $direcao = $entrada !== null ? 'entrada' : 'saida';

        $ehRamal = static fn (string $r): bool => $r !== '' && isset($ramais[$r]);
        $atendida = false;
        $conversa = 0;
        $ramal = '';
        $tocou = '';
        $gravacao = '';
        $motivo = '';

        foreach ($linhas as $l) {
            $destinoCanal = (string) ($l['dstchannel'] ?? '');
            $atendeu = ($l['disposition'] ?? '') === 'ANSWERED' && $destinoCanal !== '';
            if ($atendeu) {
                $atendida = true;
                $conversa = max($conversa, (int) $l['billsec']);
            }
            if ($gravacao === '' && trim((string) ($l['gravacao'] ?? '')) !== '') {
                $gravacao = trim((string) $l['gravacao']);
            }
            if ($direcao === 'entrada') {
                $r = self::ramalDoCanal($destinoCanal);
                if ($ehRamal($r)) {
                    $tocou = $r;
                    if ($atendeu) {
                        $ramal = $r;
                    }
                }
            }
        }

        if ($direcao === 'saida') {
            // O canal de quem discou é o do ramal — também no click to call,
            // em que o CallerID do canal é o do cliente.
            $ramal = self::ramalDoCanal((string) $saida['channel']);
            $ramal = $ehRamal($ramal) ? $ramal : (string) ($saida['src'] ?? '');
            $ramal = $ehRamal($ramal) ? $ramal : '';
            $numero = (string) $saida['dst'];
            $motivo = (string) $saida['disposition'];
        } else {
            $ramal = $ramal !== '' ? $ramal : $tocou;
            $numero = (string) $entrada['src'];
        }
        // Para onde o cliente ligou: o número da empresa (DID) na entrada.
        $did = $direcao === 'entrada' ? trim((string) ($entrada['did'] ?? '')) : '';

        $telefone = self::telefone($numero, $ddd);
        if ($telefone === '' || $ehRamal($telefone)) {
            return null;
        }

        [$status, $resultado] = match (true) {
            $atendida  => [4, 'Atendida' . ($ramal !== '' ? " pelo ramal {$ramal}" : '')
                              . ' — ' . self::tempo($conversa) . ' de conversa'],
            $direcao === 'entrada' => [3, 'Não atendida' . ($tocou !== '' ? " (tocou no ramal {$tocou})" : '')],
            $motivo === 'BUSY' => [7, 'Ocupado'],
            in_array($motivo, ['FAILED', 'CONGESTION'], true) => [6, 'Não completou (sem linha ou número inválido)'],
            default    => [3, 'Não atendeu'],
        };

        $inicio = (string) min(array_map(static fn (array $l): string => (string) $l['calldate'], $linhas));

        return [
            'linkedid' => (string) $base['linkedid'],
            'inicio'   => $inicio,
            'direcao'  => $direcao,
            'telefone' => $telefone,
            'ramal'    => $ramal,
            'atendida' => $atendida,
            'duracao'  => $atendida ? $conversa : 0,
            'status'   => $status,
            'resultado' => $resultado,
            'gravacao' => $atendida ? $gravacao : '',
            'did'      => $did,
            'tocou'    => $tocou,
        ];
    }

    /** +5511999990000 → +55 11 99999-0000, para ler no histórico. */
    public static function legivel(string $telefone): string
    {
        if (preg_match('/^\+55(\d{2})(\d{4,5})(\d{4})$/', $telefone, $m) === 1) {
            return "+55 {$m[1]} {$m[2]}-{$m[3]}";
        }

        return $telefone;
    }

    /**
     * O resumo que vai no histórico do contato: quem ligou, para onde,
     * quando e quanto durou. O Kommo mostra o sentido, a duração e o
     * dono em campos dele; o texto repete tudo numa linha, que é o que se
     * lê de relance e o que sai quando alguém copia a nota.
     *
     * @param array<string,mixed> $c de montar()
     */
    public static function resumo(array $c, ?string $nome): string
    {
        $comNome = $nome !== null && $nome !== '';
        // "por Ana Vendas (ramal 1001)" ou "pelo ramal 1001"; o mesmo para "para".
        $por = $c['ramal'] === '' ? '' : ($comNome ? " por {$nome} (ramal {$c['ramal']})" : " pelo ramal {$c['ramal']}");
        $tocou = $c['ramal'] === '' ? '' : ($comNome ? " (tocou para {$nome}, ramal {$c['ramal']})" : " (tocou no ramal {$c['ramal']})");
        $cliente = self::legivel((string) $c['telefone']);
        $quando = date('d/m/Y \à\s H:i', strtotime((string) $c['inicio']) ?: time());
        $conversa = self::tempo((int) $c['duracao']) . ' de conversa';

        if ($c['direcao'] === 'saida') {
            $texto = "Ligação feita{$por} para {$cliente} em {$quando}";

            return $texto . ' — ' . match ((int) $c['status']) {
                4 => "atendida, {$conversa}.",
                7 => 'ocupado.',
                6 => 'não completou (sem linha ou número inválido).',
                default => 'não atendeu.',
            };
        }

        $texto = "Ligação recebida de {$cliente}" . ($c['did'] !== '' ? " no número {$c['did']}" : '') . " em {$quando}";
        if ($c['atendida']) {
            return $texto . " — atendida{$por}, {$conversa}.";
        }

        return $texto . " — não atendida{$tocou}.";
    }

    private static function tempo(int $s): string
    {
        return $s >= 60 ? intdiv($s, 60) . 'min ' . ($s % 60) . 's' : "{$s}s";
    }

    /** A assinatura do link de uma gravação: sem ela, não toca. */
    public static function assinatura(string $linkedid, string $segredo): string
    {
        return substr(hash_hmac('sha256', 'gravacao:' . $linkedid, $segredo), 0, 40);
    }

    public static function linkGravacao(array $cfg, string $linkedid, string $arquivo): string
    {
        $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)) ?: 'wav';

        return self::urlPublica($cfg) . '/api/kommo/gravacao/' . rawurlencode($linkedid) . '/'
             . self::assinatura($linkedid, (string) $cfg['gravacao_segredo']) . '.' . $ext;
    }

    /**
     * O registro para POST /api/v4/calls.
     *
     * @param array<string,mixed> $c          de montar()
     * @param int|null            $usuario    o usuário do Kommo do ramal
     */
    public static function registro(array $cfg, array $c, ?int $usuario, ?string $nome = null): array
    {
        $r = [
            'direction'   => $c['direcao'] === 'entrada' ? 'inbound' : 'outbound',
            'uniq'        => $c['linkedid'],
            'duration'    => (int) $c['duracao'],
            'source'      => self::ORIGEM,
            'phone'       => $c['telefone'],
            'call_status' => $c['status'],
            'call_result' => self::resumo($c, $nome),
            'created_at'  => strtotime((string) $c['inicio']) ?: time(),
            'request_id'  => $c['linkedid'],
        ];
        if ($usuario !== null) {
            $r['responsible_user_id'] = $usuario;
            $r['created_by'] = $usuario;
        }
        if ($c['gravacao'] !== '' && !empty($cfg['gravacao_segredo']) && self::urlPublica($cfg) !== '') {
            $r['link'] = self::linkGravacao($cfg, (string) $c['linkedid'], (string) $c['gravacao']);
        }

        return $r;
    }

    /**
     * Ligação de número que não é contato: um lead de entrada, com a
     * chamada no cartão, para alguém aceitar e criar o contato.
     */
    public static function leadDeEntrada(array $cfg, array $c, array $registro): array
    {
        $meta = [
            'is_call_event_needed' => true,
            'uniq'         => $registro['uniq'],
            'duration'     => $registro['duration'],
            'service_code' => 'telium_pabx',
            'phone'        => $c['ramal'] !== '' ? $c['ramal'] : self::ORIGEM,
            'called_at'    => $registro['created_at'],
            'from'         => $c['telefone'],
        ];
        if (isset($registro['link'])) {
            $meta['link'] = $registro['link'];
        }

        return [[
            'source_name' => self::ORIGEM,
            'source_uid'  => 'telium-pabx',
            'created_at'  => $registro['created_at'],
            'metadata'    => $meta,
            '_embedded'   => [
                'leads'    => [['name' => 'Ligação de ' . $c['telefone']]],
                'contacts' => [[
                    'name' => $c['telefone'],
                    'custom_fields_values' => [[
                        'field_code' => 'PHONE',
                        'values'     => [['value' => $c['telefone'], 'enum_code' => 'WORK']],
                    ]],
                ]],
            ],
        ]];
    }

    /**
     * Manda o que está pendente. Chamado pelo temporizador.
     *
     * @return array{enviadas:int, erros:int, detalhe:string}
     */
    public static function enviarPendentes(?int $limite = null): array
    {
        $cfg = self::config();
        if (!(int) ($cfg['ativo'] ?? 0)) {
            return ['enviadas' => 0, 'erros' => 0, 'detalhe' => 'integração desligada'];
        }
        if (self::host($cfg['subdominio'] ?? null) === null || trim((string) ($cfg['token'] ?? '')) === '') {
            return ['enviadas' => 0, 'erros' => 0, 'detalhe' => 'falta a conta ou o token do Kommo'];
        }

        $direcoes = array_keys(array_filter([
            'entrada' => (int) $cfg['registrar_entrada'] === 1,
            'saida'   => (int) $cfg['registrar_saida'] === 1,
        ]));
        if ($direcoes === []) {
            return ['enviadas' => 0, 'erros' => 0, 'detalhe' => 'registro de chamadas desligado'];
        }

        $desde = (string) ($cfg['desde'] ?: date('Y-m-d H:i:s'));
        $pendentes = Bd::todos(
            "SELECT c.linkedid
               FROM cdr c
              WHERE c.calldate >= GREATEST(?, NOW() - INTERVAL 3 DAY)
                AND c.linkedid <> ''
           GROUP BY c.linkedid
             HAVING MAX(c.direcao IN ('entrada','saida')) = 1
                AND MAX(DATE_ADD(c.calldate, INTERVAL c.duration SECOND)) < NOW() - INTERVAL " . self::ESPERA_SEGUNDOS . " SECOND
                AND NOT EXISTS (
                    SELECT 1 FROM kommo_chamadas k
                     WHERE k.linkedid = c.linkedid
                       AND (k.estado <> 'erro' OR k.tentativas >= " . self::MAX_TENTATIVAS . "
                            OR k.enviado_em > NOW() - INTERVAL 5 MINUTE))
           ORDER BY MIN(c.calldate)
              LIMIT " . (int) ($limite ?? self::POR_PASSADA),
            [$desde]
        );

        $ramais = array_fill_keys(array_map('strval', array_column(Bd::todos('SELECT numero FROM ramais'), 'numero')), true);
        $usuarios = [];
        $nomes = [];
        foreach (Bd::todos('SELECT kommo_id, nome, ramal FROM kommo_usuarios WHERE ativo = 1 AND ramal IS NOT NULL') as $u) {
            $usuarios[(string) $u['ramal']] ??= (int) $u['kommo_id'];
            $nomes[(int) $u['kommo_id']] = (string) $u['nome'];
        }

        $enviadas = 0;
        $erros = 0;
        $ultimoErro = '';
        foreach ($pendentes as $p) {
            $linhas = Bd::todos('SELECT * FROM cdr WHERE linkedid = ? ORDER BY id', [$p['linkedid']]);
            $c = self::montar($linhas, $ramais, $cfg['ddd'] ?? null);
            if ($c === null || !in_array($c['direcao'], $direcoes, true)) {
                continue;
            }

            $usuario = $usuarios[$c['ramal']] ?? null;
            [$estado, $detalhe] = self::enviarUma($cfg, $c, $usuario, $usuario !== null ? $nomes[$usuario] : null);
            self::anotar($c, $usuario, $estado, $detalhe);

            if ($estado === 'erro') {
                $erros++;
                $ultimoErro = $detalhe;
                // Token recusado ou limite estourado: as próximas teriam
                // a mesma resposta. Para e tenta na próxima passada.
                if (str_contains($detalhe, 'token') || str_contains($detalhe, 'ritmo')) {
                    break;
                }
            } else {
                $enviadas++;
            }
            usleep(200000);
        }

        $resumo = $erros > 0
            ? "{$enviadas} enviada(s), {$erros} com erro: {$ultimoErro}"
            : ($enviadas > 0 ? "{$enviadas} chamada(s) enviada(s)" : 'nada novo');
        if ($pendentes !== []) {
            Bd::executar('UPDATE kommo_config SET ultimo_envio = NOW(), ultimo_estado = ? WHERE id = 1',
                         [mb_substr($resumo, 0, 255)]);
        }

        return ['enviadas' => $enviadas, 'erros' => $erros, 'detalhe' => $resumo];
    }

    /**
     * @return array{0:string, 1:string} estado e detalhe
     */
    private static function enviarUma(array $cfg, array $c, ?int $usuario, ?string $nome): array
    {
        $registro = self::registro($cfg, $c, $usuario, $nome);
        $r = self::api($cfg, 'POST', '/api/v4/calls', [$registro]);

        $nota = null;
        foreach ((array) ($r['json']['_embedded']['calls'] ?? []) as $item) {
            if ((int) ($item['entity_id'] ?? 0) > 0) {
                $nota = $item;
                break;
            }
        }
        if ($nota !== null) {
            $tipo = match ((string) ($nota['entity_type'] ?? '')) {
                'contact', 'contacts' => 'contato', 'lead', 'leads' => 'lead',
                'company', 'companies' => 'empresa', default => 'registro',
            };

            return ['ok', "no {$tipo} #" . (int) $nota['entity_id']];
        }

        // Token, assinatura, limite: o problema não é este número.
        if (in_array($r['status'], [0, 401, 402, 403, 429], true) || $r['status'] >= 500) {
            return ['erro', $r['erro'] !== '' ? $r['erro'] : "O Kommo respondeu HTTP {$r['status']}"];
        }

        // Nenhum contato com este número.
        if ($c['direcao'] === 'entrada' && (int) ($cfg['criar_lead'] ?? 0) === 1) {
            $l = self::api($cfg, 'POST', '/api/v4/leads/unsorted/sip', self::leadDeEntrada($cfg, $c, $registro));
            if ($l['erro'] !== '') {
                return ['erro', 'Número sem contato, e o lead de entrada falhou: ' . $l['erro']];
            }

            return ['lead', 'Número sem contato: virou lead de entrada'];
        }

        return ['sem_contato', 'Nenhum contato no Kommo com este número'];
    }

    private static function anotar(array $c, ?int $usuario, string $estado, string $detalhe): void
    {
        Bd::executar(
            'INSERT INTO kommo_chamadas
                (linkedid, inicio, direcao, telefone, ramal, kommo_usuario, duracao, atendida, gravacao, estado, detalhe)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), detalhe = VALUES(detalhe),
                kommo_usuario = VALUES(kommo_usuario), gravacao = VALUES(gravacao),
                tentativas = tentativas + 1, enviado_em = NOW()',
            [$c['linkedid'], $c['inicio'], $c['direcao'], mb_substr((string) $c['telefone'], 0, 40),
             $c['ramal'] !== '' ? $c['ramal'] : null, $usuario, (int) $c['duracao'], (int) $c['atendida'],
             $c['gravacao'] !== '' ? $c['gravacao'] : null, $estado, mb_substr($detalhe, 0, 255)]
        );
    }

    /**
     * O arquivo de gravação de uma ligação enviada, se a assinatura confere.
     */
    public static function arquivoDaGravacao(string $linkedid, string $assinatura): ?string
    {
        $cfg = self::config();
        $segredo = (string) ($cfg['gravacao_segredo'] ?? '');
        if ($segredo === '' || !hash_equals(self::assinatura($linkedid, $segredo), $assinatura)) {
            return null;
        }
        $arquivo = (string) (Bd::valor('SELECT gravacao FROM kommo_chamadas WHERE linkedid = ?', [$linkedid]) ?? '');

        return $arquivo !== '' ? Gravacoes::resolver($arquivo) : null;
    }
}
