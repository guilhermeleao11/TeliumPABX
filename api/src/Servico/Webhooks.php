<?php
declare(strict_types=1);

namespace Telium\Servico;

use Telium\Suporte\Bd;

/**
 * Webhooks: avisa outros sistemas (CRM, ERP) do que acontece nas chamadas.
 *
 * Roda dentro do serviço de tempo real (telium-cc), que já está ligado ao
 * AMI. Três eventos, com o nome que o cadastro usa:
 *
 *   chamada.tocando    um ramal começou a tocar — é o "screen pop" do CRM
 *   chamada.atendida   alguém atendeu
 *   chamada.encerrada  a chamada terminou, com a duração e a causa
 *
 * O envio não pode travar o serviço: ele também empurra o painel do call
 * center. As requisições vão por curl_multi, sem esperar, cada uma com
 * prazo de 5 segundos; um CRM lento perde o aviso, não atrasa a central.
 * O corpo vai assinado com o segredo do cadastro (HMAC-SHA256) no
 * cabeçalho X-Telium-Assinatura, para o outro lado saber que veio daqui.
 */
final class Webhooks
{
    public const EVENTOS = ['chamada.tocando', 'chamada.atendida', 'chamada.encerrada'];

    private const RELER_CADASTRO = 15.0;
    private const PRAZO = 5;
    private const MAX_PENDENTES = 200;

    /** @var list<array{id:int, url:string, segredo:string, eventos:list<string>}> */
    private array $destinos = [];
    private float $lidoEm = 0.0;

    /** @var array<string, array{inicio:float, atendida:?float, de:string, nome:string, para:string}> */
    private array $chamadas = [];

    /** @var \CurlMultiHandle|null */
    private $multi = null;
    /** @var array<int, array{handle:\CurlHandle, id:int}> */
    private array $emVoo = [];

    /** @param array<string,string> $ev um evento do AMI */
    public function evento(array $ev): void
    {
        if (!function_exists('curl_multi_init')) {
            return;
        }
        $this->recarregar();

        $nome = $ev['Event'] ?? '';
        $id = (string) ($ev['Linkedid'] ?? $ev['Uniqueid'] ?? '');
        if ($id === '') {
            return;
        }

        if ($nome === 'DialBegin') {
            $ramal = self::ramal((string) ($ev['DestChannel'] ?? ''));
            if ($ramal === '') {
                return;
            }
            $c = $this->chamadas[$id] ??= [
                'inicio' => microtime(true), 'atendida' => null,
                'de' => (string) ($ev['CallerIDNum'] ?? ''), 'nome' => self::nome((string) ($ev['CallerIDName'] ?? '')),
                'para' => $ramal,
            ];
            $this->disparar('chamada.tocando', $id, ['ramal' => $ramal] + self::base($c));
            return;
        }

        if ($nome === 'DialEnd' && ($ev['DialStatus'] ?? '') === 'ANSWER') {
            $ramal = self::ramal((string) ($ev['DestChannel'] ?? ''));
            if (!isset($this->chamadas[$id]) || $this->chamadas[$id]['atendida'] !== null) {
                return;
            }
            $this->chamadas[$id]['atendida'] = microtime(true);
            if ($ramal !== '') {
                $this->chamadas[$id]['para'] = $ramal;
            }
            $c = $this->chamadas[$id];
            $this->disparar('chamada.atendida', $id, [
                'ramal' => $c['para'],
                'espera_segundos' => (int) round($c['atendida'] - $c['inicio']),
            ] + self::base($c));
            return;
        }

        // O fim é o do canal que começou a chamada: os outros canais dela
        // (o do ramal, o da fila) também desligam, e cada um seria um aviso.
        if ($nome === 'Hangup' && ($ev['Uniqueid'] ?? '') === ($ev['Linkedid'] ?? '') && isset($this->chamadas[$id])) {
            $c = $this->chamadas[$id];
            unset($this->chamadas[$id]);
            $agora = microtime(true);
            $this->disparar('chamada.encerrada', $id, [
                'atendida' => $c['atendida'] !== null,
                'duracao_segundos' => (int) round($agora - $c['inicio']),
                'conversa_segundos' => $c['atendida'] !== null ? (int) round($agora - $c['atendida']) : 0,
                'causa' => (string) ($ev['Cause-txt'] ?? ''),
            ] + self::base($c));
        }
    }

    /** Chamado a cada volta do laço: anda com os envios e esquece chamada velha. */
    public function processar(): void
    {
        if ($this->multi === null) {
            return;
        }
        do {
            $estado = curl_multi_exec($this->multi, $ativos);
        } while ($estado === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $h = $info['handle'];
            $chave = spl_object_id($h);
            $id = $this->emVoo[$chave]['id'] ?? 0;
            $codigo = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $erro = curl_error($h);
            $resultado = $info['result'] === CURLE_OK && $codigo >= 200 && $codigo < 300
                ? "ok (HTTP {$codigo})"
                : ($erro !== '' ? 'falhou: ' . mb_substr($erro, 0, 90) : "falhou (HTTP {$codigo})");
            try {
                Bd::executar('UPDATE integracoes SET ultimo_disparo = NOW(), ultimo_status = ? WHERE id = ?', [$resultado, $id]);
            } catch (\Throwable) {
                // banco fora: o aviso foi; o registro fica para o próximo
            }
            curl_multi_remove_handle($this->multi, $h);
            curl_close($h);
            unset($this->emVoo[$chave]);
        }

        // Chamada que nunca recebeu o Hangup (serviço reiniciado no meio).
        $limite = microtime(true) - 6 * 3600;
        foreach ($this->chamadas as $id => $c) {
            if ($c['inicio'] < $limite) {
                unset($this->chamadas[$id]);
            }
        }
    }

    /** Uma requisição de teste, na hora, para o botão da tela. @return array{ok:bool, detalhe:string} */
    public static function testar(string $url, string $segredo): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detalhe' => 'O PHP deste servidor está sem a extensão curl.'];
        }
        $corpo = (string) json_encode([
            'evento' => 'teste', 'id' => 'teste-' . bin2hex(random_bytes(4)), 'quando' => date('c'),
            'mensagem' => 'Teste do Telium PABX: se você está lendo isto, o webhook chega.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $h = self::requisicao($url, $segredo, $corpo);
        curl_exec($h);
        $codigo = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($h);
        curl_close($h);

        return $codigo >= 200 && $codigo < 300
            ? ['ok' => true, 'detalhe' => "O destino respondeu HTTP {$codigo}."]
            : ['ok' => false, 'detalhe' => $erro !== '' ? "Não chegou: {$erro}" : "O destino respondeu HTTP {$codigo}."];
    }

    // ------------------------------------------------------------------

    private function disparar(string $evento, string $id, array $dados): void
    {
        $alvos = array_filter($this->destinos, static fn (array $d): bool => in_array($evento, $d['eventos'], true));
        if ($alvos === []) {
            return;
        }
        if (count($this->emVoo) >= self::MAX_PENDENTES) {
            return;   // destino travado: não acumula memória para sempre
        }

        $corpo = (string) json_encode(['evento' => $evento, 'id' => $id, 'quando' => date('c')] + $dados,
                                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->multi ??= curl_multi_init();
        foreach ($alvos as $d) {
            $h = self::requisicao($d['url'], $d['segredo'], $corpo);
            curl_multi_add_handle($this->multi, $h);
            $this->emVoo[spl_object_id($h)] = ['handle' => $h, 'id' => $d['id']];
        }
        $this->processar();
    }

    private static function requisicao(string $url, string $segredo, string $corpo): \CurlHandle
    {
        $cab = ['Content-Type: application/json', 'User-Agent: Telium-PABX'];
        if ($segredo !== '') {
            $cab[] = 'X-Telium-Assinatura: sha256=' . hash_hmac('sha256', $corpo, $segredo);
        }
        $h = curl_init($url);
        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => $cab,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::PRAZO,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        return $h;
    }

    private function recarregar(): void
    {
        if (microtime(true) - $this->lidoEm < self::RELER_CADASTRO) {
            return;
        }
        $this->lidoEm = microtime(true);
        try {
            $this->destinos = array_map(static fn (array $l): array => [
                'id' => (int) $l['id'],
                'url' => (string) $l['url'],
                'segredo' => (string) ($l['segredo'] ?? ''),
                'eventos' => self::eventosDe((string) ($l['eventos'] ?? '')),
            ], Bd::todos(
                "SELECT id, url, segredo, eventos FROM integracoes
                  WHERE ativo = 1 AND tipo = 'webhook' AND url LIKE 'http%'"
            ));
        } catch (\Throwable) {
            // banco fora: fica com a lista que já tinha
        }
    }

    /** @return list<string> os eventos do cadastro; vazio vale todos */
    public static function eventosDe(string $texto): array
    {
        $lista = array_values(array_intersect(
            self::EVENTOS,
            array_map('trim', preg_split('/[\s,;]+/', $texto) ?: [])
        ));

        return $lista === [] ? self::EVENTOS : $lista;
    }

    /** @param array{inicio:float, atendida:?float, de:string, nome:string, para:string} $c */
    private static function base(array $c): array
    {
        return ['de' => $c['de'], 'nome' => $c['nome'], 'para' => $c['para']];
    }

    private static function ramal(string $canal): string
    {
        return preg_match('#^PJSIP/(\d+)-#', $canal, $m) === 1 ? $m[1] : '';
    }

    private static function nome(string $nome): string
    {
        return in_array(strtolower($nome), ['', 'unknown', '<unknown>'], true) ? '' : $nome;
    }
}
