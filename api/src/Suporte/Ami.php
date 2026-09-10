<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * Cliente AMI enxuto: login, ação, logoff.
 * Usado para aplicar configuração e originar chamadas — não para
 * consumir o fluxo de eventos (isso será um daemon separado).
 */
final class Ami
{
    private $socket = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $porta = 5038,
        private readonly string $usuario = '',
        private readonly string $senha = '',
        private readonly float $timeout = 5.0,
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

    public function conectar(): void
    {
        $erro = 0;
        $msg = '';
        $this->socket = @fsockopen($this->host, $this->porta, $erro, $msg, $this->timeout);
        if (!$this->socket) {
            throw new \RuntimeException("AMI indisponível em {$this->host}:{$this->porta} — {$msg}");
        }
        stream_set_timeout($this->socket, (int) $this->timeout);
        $this->ler();                                   // banner de boas-vindas

        $r = $this->acao(['Action' => 'Login', 'Username' => $this->usuario, 'Secret' => $this->senha]);
        if (!str_contains($r, 'Success')) {
            throw new \RuntimeException('AMI recusou as credenciais: ' . trim($r));
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

    /** Executa um comando de CLI (equivalente a asterisk -rx). */
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

    private function ler(): string
    {
        $buffer = '';
        $fim = microtime(true) + $this->timeout;
        while (microtime(true) < $fim) {
            $linha = fgets($this->socket, 4096);
            if ($linha === false) {
                break;
            }
            $buffer .= $linha;
            if (str_ends_with($buffer, "\r\n\r\n") || str_ends_with($buffer, "--END COMMAND--\r\n\r\n")) {
                break;
            }
        }
        return $buffer;
    }
}
