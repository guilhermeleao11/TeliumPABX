<?php
declare(strict_types=1);

namespace Telium\Gerador;

/** Montador de trechos de dialplan — mantém a saída legível e consistente. */
final class Bloco
{
    private array $linhas = [];

    public function comentario(string $texto): static
    {
        $this->linhas[] = '; ' . $texto;
        return $this;
    }

    public function branco(): static
    {
        $this->linhas[] = '';
        return $this;
    }

    public function contexto(string $nome): static
    {
        $this->linhas[] = "[{$nome}]";
        return $this;
    }

    public function exten(string $exten, string $app): static
    {
        $this->linhas[] = "exten => {$exten},1," . self::semComentario($app);
        return $this;
    }

    public function same(string $app, ?string $rotulo = null): static
    {
        $app = self::semComentario($app);
        $this->linhas[] = $rotulo === null
            ? " same => n,{$app}"
            : " same => n({$rotulo}),{$app}";
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
        return str_replace(';', ' —', $app);
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
        $this->linhas[] = $linha;
        return $this;
    }

    public function texto(): string
    {
        return implode("\n", $this->linhas) . "\n";
    }
}
