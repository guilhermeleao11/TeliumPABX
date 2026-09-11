<?php
declare(strict_types=1);

namespace Telium\Suporte;

/**
 * Trava nomeada, compartilhada entre os processos do PHP-FPM.
 *
 * Usa GET_LOCK do MariaDB porque ela vale para a conexão inteira e cai
 * sozinha se o processo morrer no meio — um arquivo de lock esquecido
 * travaria o console até alguém apagar à mão.
 *
 * O caso concreto: dois administradores clicando "aplicar" ao mesmo
 * tempo. Cada aplicação limpa a família inteira na base do Asterisk
 * (DBDelTree) e regrava chave por chave; com as duas intercaladas, a
 * limpeza de uma apagava o que a outra acabara de escrever, e uma
 * entrada da lista negra ou um siga-me sumia até a próxima aplicação.
 */
final class Trava
{
    private bool $minha = false;

    private function __construct(private readonly string $nome)
    {
    }

    /**
     * Tenta pegar a trava por até $segundos.
     *
     * @return self|null null quando outra requisição está com ela
     */
    public static function tentar(string $nome, int $segundos = 10): ?self
    {
        $trava = new self($nome);

        try {
            $r = Bd::valor('SELECT GET_LOCK(?, ?)', [$nome, $segundos]);
        } catch (\Throwable) {
            // Banco sem suporte não pode impedir o trabalho de acontecer.
            $trava->minha = false;

            return $trava;
        }

        if ((int) $r !== 1) {
            return null;
        }

        $trava->minha = true;

        return $trava;
    }

    public function soltar(): void
    {
        if (!$this->minha) {
            return;
        }

        try {
            Bd::valor('SELECT RELEASE_LOCK(?)', [$this->nome]);
        } catch (\Throwable) {
            // A trava cai sozinha quando a conexão encerra.
        }
        $this->minha = false;
    }

    public function __destruct()
    {
        $this->soltar();
    }
}
