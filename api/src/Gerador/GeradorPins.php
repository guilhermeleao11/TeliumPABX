<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Arquivos de PIN das rotas de saída.
 *
 * A rota com conjunto de PIN chama Authenticate() apontando para um
 * destes arquivos. Eles nunca eram escritos: a chamada batia num
 * arquivo inexistente, o Authenticate recusava e a ligação morria — um
 * recurso que, ligado, quebrava a discagem em vez de controlá-la.
 *
 * O formato é o que o Authenticate espera: um PIN por linha. Com a
 * opção "a", o PIN digitado vira o accountcode da chamada e aparece no
 * CDR, que é como se cobra por centro de custo.
 */
final class GeradorPins
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $arquivos = [];

        foreach (Bd::todos('SELECT * FROM pin_sets ORDER BY id') as $conjunto) {
            $pins = [];
            foreach (preg_split('/[\s,;]+/', (string) $conjunto['pins']) ?: [] as $pin) {
                $pin = trim($pin);
                // Só dígitos: o Authenticate compara texto, e um espaço
                // invisível no fim faz o PIN certo ser recusado.
                if ($pin !== '' && preg_match('/^[0-9]{1,20}$/', $pin) === 1) {
                    $pins[] = $pin;
                }
            }

            $arquivos["pin-{$conjunto['id']}.txt"] = implode("\n", array_unique($pins)) . "\n";
        }

        return $arquivos;
    }
}
