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
        $this->linhas[] = "exten => {$exten},1,{$app}";
        return $this;
    }

    public function same(string $app, ?string $rotulo = null): static
    {
        $this->linhas[] = $rotulo === null
            ? " same => n,{$app}"
            : " same => n({$rotulo}),{$app}";
        return $this;
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
