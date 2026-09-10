<?php
declare(strict_types=1);

namespace Telium\Gerador;

/**
 * Traduz o par (destino_tipo, destino_valor) usado no banco para as
 * aplicações de dialplan correspondentes.
 */
final class Destino
{
    /** @param array<string,array<string,mixed>> $ramais indexado por número */
    public function __construct(private readonly array $ramais)
    {
    }

    /** @return string[] aplicações a executar, na ordem */
    public function linhas(?string $tipo, ?string $valor): array
    {
        return match ($tipo) {
            'ramal'     => $this->paraRamal((string) $valor),
            'fila'      => ["Queue({$valor},tT,,,300)", 'Hangup()'],
            'ura'       => ["Goto(telium-ura-{$valor},s,1)"],
            'grupo'     => ["Goto(telium-grupos,{$valor},1)"],
            'voicemail' => ["VoiceMail({$valor}@telium,u)", 'Hangup()'],
            'anuncio'   => ["Playback({$valor})", 'Hangup()'],
            'externo'   => ["Goto(telium-saida,{$valor},1)"],
            'desligar'  => ['Hangup()'],
            default     => ['NoOp(Destino não configurado)', 'Hangup()'],
        };
    }

    private function paraRamal(string $numero): array
    {
        $ramal = $this->ramais[$numero] ?? null;
        if ($ramal === null) {
            return ["NoOp(Ramal {$numero} não existe)", 'Hangup()'];
        }

        $toque = (int) ($ramal['tempo_toque'] ?: 20);
        $caixa = ((int) $ramal['voicemail'] === 1) ? "{$numero}@telium" : '';

        return ["GoSub(sub-ramal,s,1({$numero},{$toque},{$caixa}))", 'Hangup()'];
    }

    public function descricao(?string $tipo, ?string $valor): string
    {
        return match ($tipo) {
            'ramal'     => "ramal {$valor}",
            'fila'      => "fila {$valor}",
            'ura'       => "URA {$valor}",
            'grupo'     => "grupo de toque {$valor}",
            'voicemail' => "correio de voz {$valor}",
            'anuncio'   => "anúncio {$valor}",
            'externo'   => "número externo {$valor}",
            'desligar'  => 'desligar',
            default     => 'não configurado',
        };
    }
}
