<?php
declare(strict_types=1);

namespace Telium\Gerador;

/** Montador de trechos de dialplan — mantém a saída legível e consistente. */
final class Bloco
{
    private array $linhas = [];

    public function comentario(string $texto): static
    {
        $this->linhas[] = '; ' . self::umaLinha($texto);
        return $this;
    }

    public function branco(): static
    {
        $this->linhas[] = '';
        return $this;
    }

    public function contexto(string $nome): static
    {
        $this->linhas[] = '[' . self::umaLinha($nome) . ']';
        return $this;
    }

    public function exten(string $exten, string $app): static
    {
        $this->linhas[] = 'exten => ' . self::umaLinha($exten) . ',1,' . self::semComentario($app);
        return $this;
    }

    public function same(string $app, ?string $rotulo = null): static
    {
        $app = self::semComentario($app);
        $this->linhas[] = $rotulo === null
            ? " same => n,{$app}"
            : ' same => n(' . self::umaLinha($rotulo) . '),' . $app;
        return $this;
    }

    /**
     * Um ponto e vírgula começa comentário para o Asterisk, em qualquer
     * lugar da linha. Escrito dentro de um NoOp, ele corta a aplicação
     * pela metade e o dialplan não carrega — já aconteceu duas vezes,
     * então a troca acontece aqui, e não na cabeça de quem escreve.
     */
    private static function semComentario(string $app): string
    {
        return self::umaLinha(str_replace(';', ' —', $app));
    }

    /**
     * Uma linha escrita aqui é UMA linha, sempre.
     *
     * Nomes, descrições e destinos vêm do cadastro, e o cadastro vem da
     * web. Uma quebra de linha no nome de uma fila fazia o texto seguinte
     * virar dialplan de verdade: quem pudesse cadastrar uma fila
     * escrevia um "exten => 6666,1,System(...)" e executava comando no
     * servidor ao discar 6666. Este é o único lugar por onde toda linha
     * gerada passa, então a costura fica aqui.
     */
    private static function umaLinha(string $texto): string
    {
        // \r e \n abrem linha nova; \0 corta o arquivo em C.
        $limpo = str_replace(["\r\n", "\r", "\n"], ' ', $texto);

        return str_replace("\0", '', $limpo);
    }

    /** @param string[] $apps */
    public function apps(array $apps): static
    {
        foreach ($apps as $app) {
            $this->same($app);
        }
        return $this;
    }

    public function crua(string $linha): static
    {
        $this->linhas[] = self::umaLinha($linha);
        return $this;
    }

    public function texto(): string
    {
        return implode("\n", $this->linhas) . "\n";
    }
}
