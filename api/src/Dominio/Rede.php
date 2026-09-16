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
