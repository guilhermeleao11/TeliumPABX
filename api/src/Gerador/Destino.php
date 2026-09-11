<?php
declare(strict_types=1);

namespace Telium\Gerador;

/**
 * Traduz o par (destino_tipo, destino_valor) usado no banco para as
 * aplicações de dialplan correspondentes.
 */
final class Destino
{
    /**
     * @param array<string,array<string,mixed>> $ramais         indexado por número
     * @param array<int,array<string,mixed>>    $personalizados indexado por id
     */
    public function __construct(
        private readonly array $ramais,
        private readonly array $personalizados = []
    ) {
    }

    /** @return string[] aplicações a executar, na ordem */
    public function linhas(?string $tipo, ?string $valor): array
    {
        return match ($tipo) {
            'ramal'     => $this->paraRamal((string) $valor),
            // A fila tem contexto próprio, com anúncio de entrada, pesquisa
            // de satisfação e os destinos de estouro, fila vazia e fila
            // cheia. Chamar o Queue() direto daqui pulava tudo isso.
            'fila'      => ["Goto(telium-filas,{$valor},1)"],
            'ura'       => ["Goto(telium-ura-{$valor},s,1)"],
            'grupo'     => ["Goto(telium-grupos,{$valor},1)"],
            'voicemail' => ["VoiceMail({$valor}@telium,u)", 'Hangup()'],
            'anuncio'   => ["Goto(telium-anuncios,{$valor},1)"],
            'externo'   => ["Goto(telium-saida,{$valor},1)"],
            'condicao'  => ["Goto(telium-condicoes,{$valor},1)"],
            'personalizado' => $this->paraPersonalizado((string) $valor),
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

    /** Salta para um contexto escrito à mão em extensions_custom.conf. */
    private function paraPersonalizado(string $id): array
    {
        $d = $this->personalizados[(int) $id] ?? null;
        if ($d === null) {
            return ["NoOp(Destino personalizado {$id} não existe ou está desativado)", 'Hangup()'];
        }

        return [
            "NoOp(Destino personalizado: {$d['nome']})",
            "Goto({$d['contexto']},{$d['extensao']},{$d['prioridade']})",
        ];
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
            'condicao'  => "condição horária {$valor}",
            'externo'   => "número externo {$valor}",
            'personalizado' => isset($this->personalizados[(int) $valor])
                ? "destino personalizado {$this->personalizados[(int) $valor]['nome']}"
                : "destino personalizado {$valor} (não encontrado)",
            'desligar'  => 'desligar',
            default     => 'não configurado',
        };
    }
}
