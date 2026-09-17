<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

/**
 * Endereço público e faixas locais da central.
 *
 * Central atrás de NAT precisa dizer ao Asterisk qual é o IP de fora.
 * Sem isso o REGISTER sai anunciando o endereço interno — "Via:
 * 192.168.x.x" — e a operadora responde para um endereço que não existe
 * na internet: o tronco nunca registra, e nada na tela explica por quê.
 *
 * Isso já existia como variável do instalador, o que obrigava a editar
 * arquivo e rodar o playbook de novo a cada mudança de link. Agora mora
 * no banco, com o valor do instalador como ponto de partida.
 */
final class Rede
{
    /** O que o instalador deixou, usado enquanto ninguém mexeu no console. */
    private const PADRAO_LOCAIS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public static function ipPublico(): string
    {
        $guardado = self::guardado('rede_ip_publico');

        return $guardado ?? trim((string) Ambiente::get('NAT_IP_PUBLICO', ''));
    }

    /**
     * Valor gravado no console, ou null se ninguém gravou ainda.
     *
     * Bd::valor devolve false quando não há linha, não null. Tratar isso
     * como "configurado e vazio" deixava local_net sem nenhuma faixa —
     * ou seja, a rede interna inteira virando externa — logo na
     * instalação nova, que é justamente quando ninguém configurou nada.
     */
    private static function guardado(string $chave): ?string
    {
        $valor = Bd::valor('SELECT valor FROM sistema WHERE chave = ?', [$chave]);

        return is_string($valor) ? trim($valor) : null;
    }

    /** @return list<string> */
    public static function redesLocais(): array
    {
        $guardado = self::guardado('rede_locais');
        $bruto = $guardado ?? (string) Ambiente::get('REDES_LOCAIS', implode(',', self::PADRAO_LOCAIS));

        $faixas = self::faixas($bruto);

        // Lista vazia no console é escolha legítima (todo mundo é
        // externo); lista vazia porque ninguém configurou, não.
        return $guardado !== null ? $faixas : ($faixas ?: self::PADRAO_LOCAIS);
    }

    /**
     * Separa uma lista escrita à mão: vírgula, ponto-e-vírgula ou linha.
     *
     * @return list<string>
     */
    public static function faixas(string $bruto): array
    {
        $partes = preg_split('/[\s,;]+/', trim($bruto)) ?: [];

        return array_values(array_filter(array_map('trim', $partes), static fn (string $f): bool => $f !== ''));
    }

    /** Uma faixa CIDR ou um endereço solto, do jeito que o Asterisk aceita. */
    public static function faixaValida(string $faixa): bool
    {
        $partes = explode('/', $faixa, 2);
        if (filter_var($partes[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (!isset($partes[1])) {
            return true;
        }

        // O Asterisk aceita tanto /24 quanto /255.255.255.0.
        if (filter_var($partes[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        $bits = filter_var($partes[1], FILTER_VALIDATE_INT);
        $maximo = str_contains($partes[0], ':') ? 128 : 32;

        return $bits !== false && $bits >= 0 && $bits <= $maximo;
    }

    /**
     * O domínio SIP desta central — o que vai no "From" do que ela envia.
     *
     * Não é cosmético. O JsSIP, que é o softphone do navegador, segue a
     * gramática do RFC 3261 à risca: um nome de máquina precisa começar
     * por letra. O Asterisk, sem from_domain, assina OPTIONS, NOTIFY e
     * INVITE com o hostname do servidor — e um hostname que comece por
     * dígito faz o navegador DESCARTAR a mensagem inteira, sem erro
     * nenhum na tela. O efeito é o pior possível: o OPTIONS de qualify
     * não é respondido, o Asterisk marca o contato como inalcançável e
     * toda chamada para o ramal do navegador morre em "Could not create
     * dialog to invalid URI". Conferido na bancada, com hostname de
     * contêiner ("9195cd758909"): antes, nenhuma chamada entrava; com
     * from_domain, o contato volta a Avail e a chamada toca.
     *
     * Devolve vazio quando o instalador não deixou nome válido — aí o
     * gerador não escreve a opção e fica o comportamento do Asterisk.
     */
    public static function dominioSip(): string
    {
        $dominio = trim((string) Ambiente::get('SIP_DOMINIO', ''));

        return self::dominioValidoNoNavegador($dominio) ? $dominio : '';
    }

    /**
     * Um host que o JsSIP consegue analisar.
     *
     * Endereço IP serve. Nome precisa do "toplabel" do RFC 3261: começa
     * por letra, segue com letra, dígito ou hífen, e termina em
     * alfanumérico.
     */
    public static function dominioValidoNoNavegador(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $rotulos = explode('.', rtrim($host, '.'));
        foreach ($rotulos as $rotulo) {
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$/', $rotulo) !== 1) {
                return false;
            }
        }

        // Só o último rótulo é que tem a exigência de começar por letra.
        return preg_match('/^[A-Za-z]([A-Za-z0-9-]*[A-Za-z0-9])?$/', end($rotulos)) === 1;
    }

    /** Porta de cada transporte, como o instalador deixou. */
    public static function portaSip(): int
    {
        return Ambiente::int('SIP_PORTA', 5060);
    }

    public static function portaSipTls(): int
    {
        return Ambiente::int('SIP_TLS_PORTA', 5061);
    }

    public static function portaWs(): int
    {
        return Ambiente::int('AST_HTTP_PORTA', 8090);
    }

    /**
     * Servidores a quem perguntar "qual é o meu endereço público?".
     *
     * Não serve o STUN do softphone: com o TURN instalado nesta máquina,
     * ele é o STUN do navegador e está do lado de dentro do mesmo NAT —
     * perguntar a ele devolveria o endereço interno, que é exatamente o
     * que se quer descobrir que não é. Descoberta precisa de alguém de
     * fora, e é uso sob demanda: só quando alguém clica no botão.
     *
     * @return list<string>
     */
    public static function servidoresStun(): array
    {
        $bruto = (string) Ambiente::get('STUN_DESCOBERTA', 'stun.l.google.com:19302,stun.cloudflare.com:3478');
        $lista = array_filter(array_map('trim', explode(',', $bruto)));

        // Vazio é escolha legítima de quem não quer a central falando com
        // servidor de fora; o console diz isso em vez de fingir que tentou.
        return array_values($lista);
    }

    /**
     * O endereço que esta máquina usa para sair.
     *
     * Abre um socket UDP sem enviar nada: o sistema escolhe a interface
     * e devolve o endereço de origem. É como descobrir "por onde eu saio"
     * sem depender de nome de host, que em servidor costuma resolver
     * para 127.0.1.1.
     */
    public static function enderecoLocal(): string
    {
        $soquete = @stream_socket_client('udp://8.8.8.8:53', $e, $m, 1, STREAM_CLIENT_CONNECT);
        if ($soquete === false) {
            return '';
        }

        $nome = @stream_socket_get_name($soquete, false);
        @fclose($soquete);

        return is_string($nome) ? (string) strstr($nome, ':', true) : '';
    }

    /**
     * A central vai anunciar um endereço que não chega nela?
     *
     * Com o servidor atrás de NAT e sem endereço público configurado, o
     * SDP sai com o IP interno. A outra ponta manda o áudio para um
     * endereço que não existe na internet, e a chamada conecta sem som —
     * é a causa mais comum de "áudio não passa pelo servidor".
     *
     * @return array{problema:bool, local:string, publico:string}
     */
    public static function sdpSaiErrado(): array
    {
        $local = self::enderecoLocal();
        $publico = self::ipPublico();

        return [
            'problema' => $local !== '' && $publico === '' && self::ehPrivado($local),
            'local'    => $local,
            'publico'  => $publico,
        ];
    }

    /**
     * Endereço que não chega de fora — o que a descoberta não pode devolver.
     *
     * O filtro do PHP cobre 10/8, 172.16/12, 192.168/16 e os reservados,
     * mas NÃO cobre 100.64.0.0/10, o CGNAT da RFC 6598 — que é
     * exatamente onde fica quem está atrás do NAT da operadora. Sem esta
     * faixa, um servidor em CGNAT passava por "tem IP público" e o
     * diagnóstico dizia que estava tudo certo com o áudio sem passar.
     */
    public static function ehPrivado(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $n = ip2long($ip);

        return $n !== false && ($n & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    }

    /**
     * Um servidor de ICE serve para o navegador de quem usa o console?
     *
     * O navegador não roda nesta máquina: ele precisa RESOLVER o nome e
     * CHEGAR no endereço. Nome terminado em .local é mDNS e nunca vale
     * fora da própria rede; nome que não resolve, ou que resolve para
     * endereço de rede interna, deixa o navegador sem candidato — a
     * chamada conecta e não passa áudio, e o Firefox só diz "ICE failed,
     * your TURN server appears to be broken".
     *
     * Resolver aqui não prova que o navegador resolve. Mas .local,
     * endereço interno e nome que não existe são falha certa, e é isso
     * que esta conferência pega.
     *
     * @return array{servidor:string, host:string, ok:bool, motivo:string}
     */
    public static function conferirServidorIce(string $servidor): array
    {
        $host = trim(preg_replace('/^(stun|stuns|turn|turns):/i', '', trim($servidor)) ?? '');
        $host = explode('?', $host)[0];
        $host = explode(':', $host)[0];

        $r = static fn (bool $ok, string $motivo): array
            => ['servidor' => trim($servidor), 'host' => $host, 'ok' => $ok, 'motivo' => $motivo];

        if ($host === '') {
            return $r(false, 'endereço vazio');
        }

        if (str_ends_with(strtolower($host), '.local')) {
            return $r(false, 'termina em .local, que só vale dentro da própria rede — '
                           . 'o navegador de quem usa o console não resolve esse nome');
        }

        $ehIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ip = $ehIp ? $host : gethostbyname($host);

        if (!$ehIp && $ip === $host) {
            return $r(false, 'o nome não resolve nem aqui no servidor');
        }

        if (self::ehPrivado($ip)) {
            return $r(false, "resolve para {$ip}, que é endereço de rede interna — "
                           . 'o navegador de fora não alcança');
        }

        return $r(true, "resolve para {$ip}");
    }

    /**
     * Troca o host de um servidor de ICE inalcançável pelo IP público.
     *
     * O endereço do TURN vem do instalador, e o padrão é o nome da
     * central — que costuma ser interno e que o navegador de quem usa o
     * console não resolve. Corrigir isso exigia editar variável do
     * Ansible e rodar o playbook de novo; agora o console, que já sabe o
     * endereço público, conserta na hora de entregar a lista.
     *
     * Só reescreve o que está comprovadamente errado: host que resolve e
     * é alcançável fica como está, porque pode ser um TURN de terceiros.
     */
    public static function corrigirServidorIce(string $servidor): string
    {
        $exame = self::conferirServidorIce($servidor);
        if ($exame['ok'] || $exame['host'] === '') {
            return trim($servidor);
        }

        // Só o que é impossível por construção: nome .local e endereço de
        // rede interna nunca servem a um navegador de fora. Nome que
        // apenas não resolve DAQUI pode ser um TURN de terceiros com DNS
        // separado — trocá-lo pelo nosso apontaria o navegador para um
        // servidor que não conhece a credencial dele, e o aviso da tela
        // resolve melhor do que um palpite.
        $host = strtolower($exame['host']);
        $ip = filter_var($exame['host'], FILTER_VALIDATE_IP) !== false ? $exame['host'] : '';
        $impossivel = str_ends_with($host, '.local') || ($ip !== '' && self::ehPrivado($ip));
        if (!$impossivel) {
            return trim($servidor);
        }

        $publico = self::ipPublico();
        if ($publico === '') {
            return trim($servidor);   // sem para onde apontar, fica o aviso na tela
        }

        // Só o host: esquema, porta e parâmetros continuam como estavam.
        return str_replace($exame['host'], $publico, trim($servidor));
    }

    /**
     * A lista de servidores de ICE que o navegador recebe.
     *
     * Morava dentro do login. Passou para cá porque a tela de
     * diagnóstico precisa testar exatamente o que o softphone usa — e
     * testar uma lista parecida não prova nada.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function servidoresParaNavegador(): array
    {
        $lista = [];

        $stun = trim((string) Ambiente::get('SOFTPHONE_STUN', 'stun:stun.l.google.com:19302'));
        if ($stun !== '') {
            $lista[] = ['urls' => self::corrigirServidorIce($stun)];
        }

        $enderecos = array_values(array_filter(array_map(
            static fn (string $sv): string => self::corrigirServidorIce($sv),
            array_map('trim', explode(',', (string) Ambiente::get('SOFTPHONE_TURN', '')))
        )));
        if ($enderecos === []) {
            return $lista;
        }

        $segredo = (string) Ambiente::get('TURN_SEGREDO', '');
        if ($segredo !== '') {
            // Esquema use-auth-secret do coturn: o usuário é a hora em
            // que a credencial morre, e a senha é o HMAC disso.
            $validade = time() + max(600, Ambiente::int('TURN_VALIDADE_SEGUNDOS', 21600));
            $usuario = $validade . ':telium';
            $senha = base64_encode(hash_hmac('sha1', $usuario, $segredo, true));
        } else {
            $usuario = (string) Ambiente::get('SOFTPHONE_TURN_USUARIO', '');
            $senha = (string) Ambiente::get('SOFTPHONE_TURN_SENHA', '');
        }

        $lista[] = ['urls' => $enderecos, 'username' => $usuario, 'credential' => $senha];

        return $lista;
    }

    public static function guardar(string $ipPublico, string $redesLocais): void
    {
        self::gravar('rede_ip_publico', $ipPublico);
        self::gravar('rede_locais', $redesLocais);
    }

    private static function gravar(string $chave, string $valor): void
    {
        Bd::executar(
            'INSERT INTO sistema (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [$chave, $valor]
        );
    }
}
