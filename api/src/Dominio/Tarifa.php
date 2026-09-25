<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Quanto custou cada chamada.
 *
 * A conta não é "minutos vezes preço". A operadora cobra em frações: no
 * Brasil o padrão é 30 segundos de mínimo e depois de 6 em 6 segundos,
 * então uma ligação de 5 segundos custa 30, e uma de 31 custa 36. Somar
 * minutos arredondados dá um total que nunca bate com a fatura, e uma
 * conta que não bate é pior do que nenhuma.
 *
 * A classificação não é feita aqui: ela já foi feita pela rota de saída,
 * que sabe se o número é local, celular, DDD ou DDI, e o dialplan a
 * carrega até o CDR. Refazer esse julgamento com expressão regular seria
 * uma segunda verdade, e as duas divergiriam no primeiro cadastro novo.
 */
final class Tarifa
{
    /**
     * A tarifa que vale para esta chamada, ou null se nenhuma serve.
     *
     * Ordem de escolha: a mais específica primeiro. Uma tarifa com
     * padrão ganha da que cobre a classe inteira, e "ordem" desempata —
     * é o que deixa "0800 é grátis" vencer "celular custa X" sem
     * depender de qual foi cadastrada antes.
     *
     * @param list<array<string,mixed>> $tarifas
     * @return array<string,mixed>|null
     */
    public static function escolher(array $tarifas, ?string $classe, string $destino): ?array
    {
        $classe = strtolower(trim((string) $classe));

        $candidatas = array_filter($tarifas, static function (array $t) use ($classe, $destino): bool {
            if ((int) ($t['ativo'] ?? 1) !== 1) {
                return false;
            }

            $daClasse = (string) ($t['classe'] ?? 'qualquer');
            if ($daClasse !== 'qualquer' && $daClasse !== $classe) {
                return false;
            }

            $padrao = trim((string) ($t['padrao'] ?? ''));

            return $padrao === '' || self::casa($padrao, $destino);
        });

        if ($candidatas === []) {
            return null;
        }

        // Com padrão vem antes de sem padrão; depois vale a ordem, e o
        // id fecha o empate para a escolha ser sempre a mesma.
        usort($candidatas, static function (array $a, array $b): int {
            $temPadrao = static fn (array $t): int => trim((string) ($t['padrao'] ?? '')) === '' ? 1 : 0;

            return [$temPadrao($a), (int) ($a['ordem'] ?? 100), (int) ($a['id'] ?? 0)]
               <=> [$temPadrao($b), (int) ($b['ordem'] ?? 100), (int) ($b['id'] ?? 0)];
        });

        return $candidatas[0];
    }

    /**
     * O padrão casa com o número?
     *
     * Mesma gramática que o resto do produto usa nas rotas de saída, que
     * é a do Asterisk — quem cadastra não precisa aprender uma segunda:
     *
     *   X  um dígito de 0 a 9        N  de 2 a 9
     *   Z  de 1 a 9                  .  um ou mais caracteres
     *   !  zero ou mais caracteres   [147] um dos que estiverem entre colchetes
     *
     * O "_" da frente é opcional, e um padrão sem curinga nenhum casa
     * por igualdade — não por começo: "11" não pode cobrar a conta de
     * "1199999999".
     */
    public static function casa(string $padrao, string $numero): bool
    {
        $padrao = trim($padrao);
        $numero = trim($numero);

        if ($padrao === '' || $numero === '') {
            return false;
        }

        if (str_starts_with($padrao, '_')) {
            $padrao = substr($padrao, 1);
        }

        $regex = '';
        $tamanho = strlen($padrao);

        for ($i = 0; $i < $tamanho; $i++) {
            $c = $padrao[$i];

            if ($c === '[') {
                $fim = strpos($padrao, ']', $i);
                if ($fim === false) {
                    return false;           // colchete sem fechar: padrão inválido
                }
                $conjunto = substr($padrao, $i + 1, $fim - $i - 1);
                // Só dígitos, hífen de faixa e nada mais: o conteúdo vem
                // de um cadastro, e daqui ele vira expressão regular.
                if (preg_match('/^[0-9-]+$/', $conjunto) !== 1) {
                    return false;
                }
                $regex .= '[' . $conjunto . ']';
                $i = $fim;
                continue;
            }

            $regex .= match (strtoupper($c)) {
                'X'     => '[0-9]',
                'N'     => '[2-9]',
                'Z'     => '[1-9]',
                '.'     => '.+',
                '!'     => '.*',
                default => preg_quote($c, '/'),
            };
        }

        return preg_match('/^' . $regex . '$/', $numero) === 1;
    }

    /**
     * O custo de uma chamada, em reais.
     *
     * Chamada não atendida não custa: billsec é o tempo conversado, e é
     * sobre ele que a operadora cobra.
     *
     * @param array<string,mixed> $tarifa
     */
    public static function custo(array $tarifa, int $billsec): float
    {
        if ($billsec <= 0) {
            return 0.0;
        }

        $primeiro = max(0, (int) ($tarifa['primeiro_incremento_seg'] ?? 30));
        $incremento = max(1, (int) ($tarifa['incremento_seg'] ?? 6));

        // Mínimo da primeira fração, e depois de incremento em incremento.
        $cobrado = $billsec <= $primeiro
            ? $primeiro
            : $primeiro + (int) ceil(($billsec - $primeiro) / $incremento) * $incremento;

        $custo = (float) ($tarifa['taxa_fixa'] ?? 0)
               + ($cobrado / 60) * (float) ($tarifa['custo_minuto'] ?? 0);

        // Quatro casas é o que a coluna guarda; centavo de fração some
        // na soma do mês e a conta deixa de fechar.
        return round($custo, 4);
    }

    /**
     * Preenche o custo das chamadas que ainda não têm.
     *
     * Roda por fora da chamada, e é de propósito: calcular no dialplan
     * significaria consultar o banco no meio da ligação, e um banco lento
     * viraria atraso no atendimento. Aqui, uma chamada tarifada não é
     * recalculada — o preço fica congelado no que valia quando ela
     * aconteceu, que é como uma fatura precisa se comportar.
     *
     * @return array{tarifadas:int, sem_tarifa:int, total:float}
     */
    public static function aplicar(?string $mes = null, int $teto = 20000): array
    {
        $tarifas = Bd::todos('SELECT * FROM tarifas WHERE ativo = 1 ORDER BY ordem, id');
        if ($tarifas === []) {
            return ['tarifadas' => 0, 'sem_tarifa' => 0, 'total' => 0.0];
        }

        $onde = ["direcao = 'saida'", 'custo IS NULL'];
        $args = [];
        if ($mes !== null && $mes !== '') {
            $onde[] = "DATE_FORMAT(calldate, '%Y-%m') = ?";
            $args[] = $mes;
        }

        $pendentes = Bd::todos(
            'SELECT id, dst, billsec, classe FROM cdr WHERE ' . implode(' AND ', $onde)
            . ' ORDER BY calldate LIMIT ' . max(1, $teto),
            $args
        );

        $tarifadas = 0;
        $semTarifa = 0;
        $total = 0.0;

        foreach ($pendentes as $c) {
            $t = self::escolher($tarifas, $c['classe'] ?? null, (string) ($c['dst'] ?? ''));
            if ($t === null) {
                $semTarifa++;
                continue;
            }

            $custo = self::custo($t, (int) $c['billsec']);
            Bd::executar('UPDATE cdr SET custo = ? WHERE id = ?', [$custo, (int) $c['id']]);
            $tarifadas++;
            $total += $custo;
        }

        return ['tarifadas' => $tarifadas, 'sem_tarifa' => $semTarifa, 'total' => round($total, 4)];
    }

    /**
     * Toda tarifa está com preço zero?
     *
     * A tabela nasce com uma linha por classe e preço em branco, para a
     * classificação já funcionar. Enquanto ninguém digitar os valores da
     * operadora, o relatório fecha em R$ 0,00 — e precisa dizer por quê,
     * em vez de deixar o zero passar por resposta.
     */
    public static function tudoZerado(): bool
    {
        $comPreco = (int) Bd::valor(
            'SELECT COUNT(*) FROM tarifas WHERE ativo = 1 AND (custo_minuto > 0 OR taxa_fixa > 0)'
        );

        return $comPreco === 0;
    }
}
