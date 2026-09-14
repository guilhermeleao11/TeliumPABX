<?php
declare(strict_types=1);

namespace Telium\Dominio;

/**
 * Descobre o endereço público da central perguntando a um STUN (RFC 5389).
 *
 * Quem instala atrás de NAT precisa dizer ao Asterisk qual é o IP de
 * fora, senão o REGISTER sai anunciando o endereço interno e a
 * operadora responde para um endereço que não existe na internet. Pedir
 * esse número ao cliente é pedir o que ele não sabe, então o console
 * oferece descobrir — e quem responde é o mesmo servidor STUN que o
 * softphone do navegador já usa, sem dependência nova.
 *
 * Só descobre: escrever no cadastro continua sendo decisão de quem
 * instala, porque em rede com IP público direto o campo tem de ficar
 * vazio.
 */
final class Stun
{
    private const TIPO_BINDING_REQUEST  = 0x0001;
    private const TIPO_BINDING_RESPONSE = 0x0101;

    /** O "magic cookie" que separa o RFC 5389 do STUN antigo. */
    private const COOKIE = 0x2112A442;

    private const ATRIBUTO_MAPPED     = 0x0001;
    private const ATRIBUTO_XOR_MAPPED = 0x0020;

    /**
     * Endereço público visto pelo servidor STUN, ou null se ninguém
     * respondeu.
     *
     * @param string $servidor "host:porta"; a porta é 3478 se faltar
     */
    public static function enderecoPublico(string $servidor, float $espera = 3.0): ?string
    {
        [$host, $porta] = self::separar($servidor);
        if ($host === '') {
            return null;
        }

        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;                          // nome que não resolve
        }

        $soquete = @stream_socket_client(
            "udp://{$ip}:{$porta}",
            $erro,
            $mensagem,
            $espera,
            STREAM_CLIENT_CONNECT
        );
        if ($soquete === false) {
            return null;
        }

        try {
            $transacao = random_bytes(12);
            $pedido = pack('nnN', self::TIPO_BINDING_REQUEST, 0, self::COOKIE) . $transacao;
            if (@fwrite($soquete, $pedido) === false) {
                return null;
            }

            stream_set_timeout($soquete, (int) $espera, (int) (fmod($espera, 1) * 1_000_000));
            $resposta = @fread($soquete, 1500);

            return is_string($resposta) ? self::lerResposta($resposta, $transacao) : null;
        } finally {
            @fclose($soquete);
        }
    }

    /**
     * Primeiro endereço público que algum dos servidores souber dizer.
     *
     * @param list<string> $servidores
     */
    public static function primeiroQueResponder(array $servidores, float $espera = 3.0): ?string
    {
        foreach ($servidores as $servidor) {
            $ip = self::enderecoPublico(trim($servidor), $espera);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    /** @return array{0:string,1:int} */
    private static function separar(string $servidor): array
    {
        $servidor = trim($servidor);
        // Aceita o que estiver guardado para o softphone: "stun:host:porta".
        $servidor = preg_replace('/^stuns?:/i', '', $servidor) ?? $servidor;
        if ($servidor === '') {
            return ['', 3478];
        }

        $partes = explode(':', $servidor);
        $host = trim($partes[0]);
        $porta = isset($partes[1]) ? (int) $partes[1] : 3478;

        return [$host, $porta > 0 && $porta < 65536 ? $porta : 3478];
    }

    /** O IP de dentro da resposta, conferindo que ela é da nossa pergunta. */
    public static function lerResposta(string $pacote, string $transacao): ?string
    {
        if (strlen($pacote) < 20) {
            return null;
        }

        $cabecalho = unpack('ntipo/ntamanho/Ncookie', substr($pacote, 0, 8));
        if ($cabecalho === false
            || $cabecalho['tipo'] !== self::TIPO_BINDING_RESPONSE
            || $cabecalho['cookie'] !== self::COOKIE
            || !hash_equals($transacao, substr($pacote, 8, 12))
        ) {
            return null;
        }

        $fim = min(strlen($pacote), 20 + $cabecalho['tamanho']);
        $i = 20;
        while ($i + 4 <= $fim) {
            $atributo = unpack('ntipo/ntamanho', substr($pacote, $i, 4));
            if ($atributo === false) {
                return null;
            }
            $valor = substr($pacote, $i + 4, $atributo['tamanho']);

            if ($atributo['tipo'] === self::ATRIBUTO_XOR_MAPPED) {
                $ip = self::endereco($valor, true);
                if ($ip !== null) {
                    return $ip;
                }
            }
            if ($atributo['tipo'] === self::ATRIBUTO_MAPPED) {
                $ip = self::endereco($valor, false);
                if ($ip !== null) {
                    return $ip;
                }
            }

            // Cada atributo é preenchido até fechar em quatro bytes.
            $i += 4 + $atributo['tamanho'] + ((4 - $atributo['tamanho'] % 4) % 4);
        }

        return null;
    }

    /** IPv4 de um atributo de endereço; no XOR ele vem embaralhado com o cookie. */
    private static function endereco(string $valor, bool $comXor): ?string
    {
        // 1 byte reservado, 1 de família, 2 de porta, 4 de endereço.
        if (strlen($valor) < 8 || ord($valor[1]) !== 0x01) {
            return null;                          // só IPv4 interessa aqui
        }

        $bruto = substr($valor, 4, 4);
        if ($comXor) {
            $bruto ^= pack('N', self::COOKIE);
        }

        $ip = inet_ntop($bruto);

        return is_string($ip) ? $ip : null;
    }
}
