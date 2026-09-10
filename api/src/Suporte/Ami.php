<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * Cliente AMI enxuto: login, ação, logoff.
 *
 * Usado para aplicar configuração, ler o estado do Asterisk e originar
 * chamadas — não para consumir o fluxo de eventos, que será um daemon
 * à parte.
 */
final class Ami
{
    private $socket = null;

    /** Conexão compartilhada pela requisição inteira. */
    private static ?self $compartilhada = null;
    private static ?\Throwable $falha = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $porta = 5038,
        private readonly string $usuario = '',
        private readonly string $senha = '',
        private readonly float $timeout = 3.0,
    ) {
    }

    public static function doAmbiente(): self
    {
        return new self(
            Ambiente::get('AMI_HOST', '127.0.0.1'),
            Ambiente::int('AMI_PORT', 5038),
            (string) Ambiente::get('AMI_USER'),
            (string) Ambiente::get('AMI_PASS'),
        );
    }

    /**
     * Uma única conexão por requisição.
     *
     * Antes cada consulta abria a sua, e uma central fora do ar fazia a
     * página pagar o custo várias vezes. Aqui a primeira falha é lembrada
     * e as chamadas seguintes falham de imediato.
     */
    public static function compartilhada(): self
    {
        if (self::$falha !== null) {
            throw self::$falha;
        }
        if (self::$compartilhada instanceof self) {
            return self::$compartilhada;
        }

        $ami = self::doAmbiente();
        try {
            $ami->conectar();
        } catch (\Throwable $e) {
            self::$falha = $e;
            throw $e;
        }

        self::$compartilhada = $ami;
        register_shutdown_function(static function (): void {
            self::$compartilhada?->desconectar();
            self::$compartilhada = null;
        });

        return $ami;
    }

    /** Executa um comando de CLI na conexão compartilhada, ou null se o AMI estiver fora. */
    public static function tentarComando(string $comando): ?string
    {
        try {
            return self::compartilhada()->comando($comando);
        } catch (\Throwable) {
            return null;
        }
    }

    public function conectar(): void
    {
        $erro = 0;
        $msg = '';
        $this->socket = @fsockopen($this->host, $this->porta, $erro, $msg, $this->timeout);
        if (!$this->socket) {
            throw new \RuntimeException("AMI indisponível em {$this->host}:{$this->porta} — {$msg}");
        }
        stream_set_timeout($this->socket, (int) $this->timeout);

        // O banner é UMA linha ("Asterisk Call Manager/x.y.z") e não termina
        // com linha em branco. Ler com o leitor de pacotes esperava o timeout
        // inteiro a cada conexão.
        $banner = fgets($this->socket, 1024);
        if ($banner === false) {
            $this->desconectar();
            throw new \RuntimeException('O AMI aceitou a conexão mas não enviou o banner.');
        }

        $r = $this->acao(['Action' => 'Login', 'Username' => $this->usuario, 'Secret' => $this->senha]);
        if (!str_contains($r, 'Success')) {
            $this->desconectar();
            throw new \RuntimeException('AMI recusou as credenciais: ' . $this->resumo($r));
        }
    }

    public function acao(array $campos): string
    {
        if (!$this->socket) {
            throw new \RuntimeException('AMI não conectado');
        }
        $pacote = '';
        foreach ($campos as $chave => $valor) {
            $pacote .= "{$chave}: {$valor}\r\n";
        }
        fwrite($this->socket, $pacote . "\r\n");

        return $this->ler();
    }

    public function comando(string $comando): string
    {
        return $this->acao(['Action' => 'Command', 'Command' => $comando]);
    }

    public function desconectar(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, "Action: Logoff\r\n\r\n");
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /** Lê um pacote do AMI: termina na linha em branco. */
    private function ler(): string
    {
        $buffer = '';
        $limite = microtime(true) + $this->timeout;

        while (microtime(true) < $limite) {
            $linha = fgets($this->socket, 8192);

            if ($linha === false) {
                break;                                   // timeout de leitura ou conexão encerrada
            }

            $buffer .= $linha;

            // Fim de pacote: linha em branco. O Asterisk usa CRLF, mas
            // aceitamos LF puro por segurança.
            if (str_ends_with($buffer, "\r\n\r\n") || str_ends_with($buffer, "\n\n")) {
                break;
            }
        }

        return $buffer;
    }

    private function resumo(string $resposta): string
    {
        foreach (explode("\n", $resposta) as $linha) {
            if (str_starts_with($linha, 'Message:')) {
                return trim(substr($linha, 8));
            }
        }
        return trim($resposta);
    }
}
