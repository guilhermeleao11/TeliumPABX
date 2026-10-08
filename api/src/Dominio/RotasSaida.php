<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Gerador\Padrao;
use Telium\Suporte\Bd;

/**
 * Os padrões de discagem de uma rota de saída.
 *
 * Uma rota tem um ou mais padrões, e cada um traz os próprios prefixos:
 * "Celular 11" pega 9XXXXXXXX como está e 09XXXXXXXX tirando o 0, e a
 * operadora recebe o mesmo número dos dois jeitos. O resto — tronco,
 * classe, PIN, reserva — é da rota e vale para todos.
 *
 * A lista chega inteira no mesmo formulário da rota e troca a anterior
 * de uma vez, como as faixas do grupo de horário.
 */
final class RotasSaida
{
    /** Mais que isto é engano de quem colou, não uma rota. */
    private const MAXIMO = 50;

    /**
     * Os padrões de cada rota pedida, na ordem em que foram cadastrados.
     *
     * @param  list<int|string> $ids
     * @return array<int, list<array{padrao:string, prefixo_remover:?string, prefixo_adicionar:?string}>>
     */
    public static function padroesPorRota(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $por = [];
        foreach (Bd::todos(
            'SELECT rota_id, padrao, prefixo_remover, prefixo_adicionar
               FROM rota_saida_padroes
              WHERE rota_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
           ORDER BY rota_id, ordem, id',
            $ids
        ) as $p) {
            $por[(int) $p['rota_id']][] = [
                'padrao'            => (string) $p['padrao'],
                'prefixo_remover'   => $p['prefixo_remover'],
                'prefixo_adicionar' => $p['prefixo_adicionar'],
            ];
        }

        return $por;
    }

    /**
     * Confere e arruma a lista que veio do formulário.
     *
     * Aceita a lista como JSON (é como o formulário a manda, num campo
     * só) ou já como array; cada item pode ser só o padrão, em texto.
     *
     * @return array{lista: list<array{padrao:string, prefixo_remover:?string, prefixo_adicionar:?string}>}|array{mensagem:string}
     */
    public static function prepararPadroes(mixed $bruto): array
    {
        if (is_string($bruto)) {
            $bruto = trim($bruto) === '' ? [] : json_decode($bruto, true);
        }
        if (!is_array($bruto)) {
            return ['mensagem' => 'A lista de padrões chegou em formato inválido.'];
        }

        $lista = [];
        $vistos = [];
        foreach (array_values($bruto) as $i => $item) {
            $item = is_array($item) ? $item : ['padrao' => $item];
            $n = $i + 1;

            $original = trim((string) ($item['padrao'] ?? ''));
            $remover = trim((string) ($item['prefixo_remover'] ?? ''));
            $adicionar = trim((string) ($item['prefixo_adicionar'] ?? ''));

            // Linha toda em branco é a que a pessoa acrescentou e não usou.
            if ($original === '' && $remover === '' && $adicionar === '') {
                continue;
            }
            if ($original === '') {
                return ['mensagem' => "O padrão {$n} está sem o número: preencha ou tire a linha."];
            }
            if (preg_match('/^_?[0-9XZNxzn.!\[\]*#+\s-]{1,40}$/', $original) !== 1) {
                return ['mensagem' => "O padrão \"{$original}\" não vale: use número ou X (qualquer dígito), "
                                    . 'Z (1 a 9), N (2 a 9), [2-5] (faixa) e . no fim (o resto do número). '
                                    . 'Ex.: X. para tudo.'];
            }
            // Os prefixos entram no Dial: qualquer coisa além de dígito
            // (um "&", por exemplo) acrescentaria canais à discagem.
            foreach (['tirar' => $remover, 'pôr' => $adicionar] as $o => $prefixo) {
                if (preg_match('/^[0-9*#+]{0,10}$/', $prefixo) !== 1) {
                    return ['mensagem' => "O prefixo a {$o} do padrão \"{$original}\" é só de dígitos."];
                }
            }

            $padrao = Padrao::normalizar($original);
            if (isset($vistos[$padrao])) {
                return ['mensagem' => "O padrão \"{$padrao}\" aparece duas vezes nesta rota."];
            }
            $vistos[$padrao] = true;

            // O dialplan corta tantos dígitos quantos o prefixo tiver, sem
            // olhar quais são: o 0 a remover posto no 9XXXXXXXX comia o 9
            // e a operadora recebia um número a menos, sem erro nenhum.
            if ($remover !== '' && !self::comecaCom($padrao, $remover)) {
                return ['mensagem' => "O padrão \"{$padrao}\" não começa com \"{$remover}\": "
                                    . 'tirar esse prefixo cortaria outros dígitos do número.'];
            }

            $lista[] = [
                'padrao'            => $padrao,
                'prefixo_remover'   => $remover === '' ? null : $remover,
                'prefixo_adicionar' => $adicionar === '' ? null : $adicionar,
            ];
        }

        if ($lista === []) {
            return ['mensagem' => 'A rota precisa de pelo menos um padrão de discagem.'];
        }
        if (count($lista) > self::MAXIMO) {
            return ['mensagem' => 'São padrões demais numa rota só (o máximo é ' . self::MAXIMO . ').'];
        }

        return ['lista' => $lista];
    }

    /**
     * Troca a lista inteira de padrões da rota. Quem chama abre a
     * transação: a rota e os padrões dela gravam juntos ou não gravam.
     *
     * @param list<array{padrao:string, prefixo_remover:?string, prefixo_adicionar:?string}> $lista
     */
    public static function gravarPadroes(int $rotaId, array $lista): void
    {
        Bd::executar('DELETE FROM rota_saida_padroes WHERE rota_id = ?', [$rotaId]);
        foreach (array_values($lista) as $i => $p) {
            Bd::executar(
                'INSERT INTO rota_saida_padroes (rota_id, padrao, prefixo_remover, prefixo_adicionar, ordem)
                 VALUES (?, ?, ?, ?, ?)',
                [$rotaId, $p['padrao'], $p['prefixo_remover'], $p['prefixo_adicionar'], ($i + 1) * 10]
            );
        }
    }

    /**
     * Todo número que o padrão pega começa com estes dígitos? Uma
     * posição com X, Z, N ou faixa basta poder ser o dígito; o "." e o
     * "!" aceitam qualquer coisa dali em diante.
     */
    private static function comecaCom(string $padrao, string $prefixo): bool
    {
        if (!str_starts_with($padrao, '_')) {
            return str_starts_with($padrao, $prefixo);
        }

        preg_match_all('/\[[^\]]*\]|./', substr($padrao, 1), $m);
        $pecas = $m[0];
        foreach (str_split($prefixo) as $i => $digito) {
            $peca = $pecas[$i] ?? null;
            if ($peca === null) {
                return false;
            }
            if ($peca === '.' || $peca === '!') {
                return true;
            }
            $aceita = match ($peca) {
                'X'     => ctype_digit($digito),
                'Z'     => $digito >= '1' && $digito <= '9',
                'N'     => $digito >= '2' && $digito <= '9',
                default => $peca[0] === '['
                    ? @preg_match('/^' . $peca . '$/', $digito) === 1
                    : $peca === $digito,
            };
            if (!$aceita) {
                return false;
            }
        }

        return true;
    }
}
