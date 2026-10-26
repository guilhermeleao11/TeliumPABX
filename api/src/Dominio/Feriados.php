<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * Feriados: as datas de cada um e o que o cadastro não aceita.
 *
 * O GotoIfTime do Asterisk não conhece o ano, e a Páscoa (com o Carnaval,
 * a Sexta-feira Santa e o Corpus Christi, que dependem dela) cai num dia
 * diferente a cada ano. Por isso o dialplan não pergunta ao GotoIfTime:
 * compara a data de hoje, AAAA-MM-DD, com uma expressão regular. Para o
 * feriado fixo a expressão ignora o ano; para o móvel e o de data única
 * ela lista os dias, calculados aqui para os próximos ANOS anos — cada
 * "Aplicar" recalcula, e a virada do ano não pede nada a ninguém.
 */
final class Feriados
{
    /** Quantos anos à frente o dialplan leva já calculados. */
    public const ANOS = 20;
    /** O maior intervalo de um feriado, em dias. */
    public const MAX_DIAS = 31;

    /** Os feriados que dependem da Páscoa: dias de distância dela. */
    public const MOVEIS = [
        'carnaval'       => ['rotulo' => 'Carnaval (segunda e terça)', 'dias' => [-48, -47]],
        'cinzas'         => ['rotulo' => 'Quarta-feira de Cinzas', 'dias' => [-46]],
        'sexta_santa'    => ['rotulo' => 'Sexta-feira Santa', 'dias' => [-2]],
        'pascoa'         => ['rotulo' => 'Páscoa', 'dias' => [0]],
        'corpus_christi' => ['rotulo' => 'Corpus Christi', 'dias' => [60]],
    ];

    /**
     * O que "Carregar feriados nacionais" cadastra: os feriados nacionais,
     * só o dia. A véspera (24/12, 31/12) fica para cada empresa estender,
     * e Carnaval e Corpus Christi, que são ponto facultativo, entram
     * desativados — a empresa que abre nesses dias não fecha de surpresa.
     */
    public const NACIONAIS = [
        ['nome' => 'Ano Novo', 'tipo' => 'fixo', 'dia_inicio' => 1, 'mes_inicio' => 1],
        ['nome' => 'Carnaval', 'tipo' => 'movel', 'movel' => 'carnaval', 'ativo' => 0],
        ['nome' => 'Sexta-feira Santa', 'tipo' => 'movel', 'movel' => 'sexta_santa'],
        ['nome' => 'Páscoa', 'tipo' => 'movel', 'movel' => 'pascoa'],
        ['nome' => 'Tiradentes', 'tipo' => 'fixo', 'dia_inicio' => 21, 'mes_inicio' => 4],
        ['nome' => 'Dia do Trabalho', 'tipo' => 'fixo', 'dia_inicio' => 1, 'mes_inicio' => 5],
        ['nome' => 'Corpus Christi', 'tipo' => 'movel', 'movel' => 'corpus_christi', 'ativo' => 0],
        ['nome' => 'Independência', 'tipo' => 'fixo', 'dia_inicio' => 7, 'mes_inicio' => 9],
        ['nome' => 'Nossa Senhora Aparecida', 'tipo' => 'fixo', 'dia_inicio' => 12, 'mes_inicio' => 10],
        ['nome' => 'Finados', 'tipo' => 'fixo', 'dia_inicio' => 2, 'mes_inicio' => 11],
        ['nome' => 'Proclamação da República', 'tipo' => 'fixo', 'dia_inicio' => 15, 'mes_inicio' => 11],
        ['nome' => 'Consciência Negra', 'tipo' => 'fixo', 'dia_inicio' => 20, 'mes_inicio' => 11],
        ['nome' => 'Natal', 'tipo' => 'fixo', 'dia_inicio' => 25, 'mes_inicio' => 12],
    ];

    /** O domingo de Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano). */
    public static function pascoa(int $ano): \DateTimeImmutable
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
    }

    /**
     * Os dias do feriado num ano, AAAA-MM-DD. O fixo que vira o ano
     * (31/12 a 01/01) devolve os dias que caem no ano pedido.
     *
     * @param array<string,mixed> $f
     * @return list<string>
     */
    public static function datas(array $f, int $ano): array
    {
        switch ((string) ($f['tipo'] ?? '')) {
            case 'movel':
                $base = self::pascoa($ano);
                return array_map(
                    static fn (int $d): string => $base->modify(sprintf('%+d days', $d))->format('Y-m-d'),
                    self::MOVEIS[(string) ($f['movel'] ?? '')]['dias'] ?? []
                );
            case 'data':
                return array_values(array_filter(self::diasEntre((string) $f['data_inicio'],
                    (string) ($f['data_fim'] ?: $f['data_inicio'])),
                    static fn (string $d): bool => (int) substr($d, 0, 4) === $ano));
            case 'fixo':
                $dias = array_values(array_filter(array_map(
                    static fn (string $md): string => "{$ano}-{$md}",
                    self::diasDoFixo($f)
                ), static fn (string $d): bool => checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), $ano)));
                sort($dias);    // o 01/01 do Ano Novo antes do 31/12

                return $dias;
        }

        return [];
    }

    /**
     * A expressão regular que casa com o dia de hoje (AAAA-MM-DD) quando
     * é este feriado. Null: o feriado não cai em dia nenhum daqui para a
     * frente (a data única que já passou).
     *
     * @param array<string,mixed> $f
     */
    public static function expressao(array $f, int $anoAtual): ?string
    {
        if (($f['tipo'] ?? '') === 'fixo') {
            $dias = self::diasDoFixo($f);

            return $dias === [] ? null : '^[0-9]{4}-(' . implode('|', $dias) . ')$';
        }

        $dias = [];
        for ($ano = $anoAtual - 1; $ano <= $anoAtual + self::ANOS; $ano++) {
            array_push($dias, ...self::datas($f, $ano));
        }
        $dias = array_values(array_unique(array_filter($dias,
            static fn (string $d): bool => (int) substr($d, 0, 4) >= $anoAtual - 1)));

        return $dias === [] ? null : '^(' . implode('|', $dias) . ')$';
    }

    /**
     * O feriado que vale agora, na mesma ordem do dialplan: a data de um
     * ano só, depois o que tem hora, depois a ordem do cadastro. Null: não
     * é feriado. É o que a tela mostra; quem decide a chamada é o dialplan.
     *
     * @return array<string,mixed>|null
     */
    public static function deAgora(\DateTimeImmutable $agora): ?array
    {
        try {
            $lista = Bd::todos('SELECT * FROM feriados WHERE ativo = 1');
        } catch (\Throwable) {
            return null;            // sem a migração 49: sem feriados
        }
        usort($lista, static fn (array $x, array $y): int =>
            [$x['tipo'] !== 'data', $x['hora_inicio'] === null, (int) $x['id']]
            <=> [$y['tipo'] !== 'data', $y['hora_inicio'] === null, (int) $y['id']]);

        $hoje = $agora->format('Y-m-d');
        $minuto = (int) $agora->format('H') * 60 + (int) $agora->format('i');
        foreach ($lista as $f) {
            if (!in_array($hoje, self::datas($f, (int) $agora->format('Y')), true)) {
                continue;
            }
            if ($f['hora_inicio'] === null
                || ($minuto >= self::minutos((string) $f['hora_inicio']) && $minuto < self::minutos((string) $f['hora_fim']))) {
                return $f;
            }
        }

        return null;
    }

    /** Uma linha legível da data: "24/12 a 25/12", "Páscoa", "20/02/2026". */
    public static function descricao(array $f): string
    {
        $dm = static fn ($d, $m): string => sprintf('%02d/%02d', (int) $d, (int) $m);
        $br = static fn (string $d): string => implode('/', array_reverse(explode('-', substr($d, 0, 10))));

        return match ((string) ($f['tipo'] ?? '')) {
            'movel' => self::MOVEIS[(string) $f['movel']]['rotulo'] ?? (string) $f['movel'],
            'data'  => $br((string) $f['data_inicio'])
                . (($f['data_fim'] ?? '') !== '' && $f['data_fim'] !== null && $f['data_fim'] !== $f['data_inicio']
                    ? ' a ' . $br((string) $f['data_fim']) : ''),
            default => $dm($f['dia_inicio'], $f['mes_inicio'])
                . (($f['dia_fim'] ?? null) !== null && ($f['dia_fim'] !== $f['dia_inicio'] || $f['mes_fim'] !== $f['mes_inicio'])
                    ? ' a ' . $dm($f['dia_fim'], $f['mes_fim']) : ''),
        };
    }

    /** Os campos em branco viram NULL, e o que não é do tipo escolhido sai. */
    public static function normalizar(array $dados): array
    {
        foreach (['dia_inicio', 'mes_inicio', 'dia_fim', 'mes_fim', 'data_inicio', 'data_fim', 'movel',
                  'hora_inicio', 'hora_fim', 'audio_id', 'destino_tipo', 'destino_valor'] as $c) {
            if (array_key_exists($c, $dados) && trim((string) $dados[$c]) === '') {
                $dados[$c] = null;
            }
        }
        if (isset($dados['tipo'])) {
            $fora = match ((string) $dados['tipo']) {
                'fixo'  => ['data_inicio', 'data_fim', 'movel'],
                'data'  => ['dia_inicio', 'mes_inicio', 'dia_fim', 'mes_fim', 'movel'],
                'movel' => ['dia_inicio', 'mes_inicio', 'dia_fim', 'mes_fim', 'data_inicio', 'data_fim'],
                default => [],
            };
            foreach ($fora as $c) {
                $dados[$c] = null;
            }
        }
        // A data final igual à inicial é um dia só.
        if (($dados['dia_fim'] ?? null) !== null && ($dados['mes_fim'] ?? null) === null) {
            $dados['mes_fim'] = $dados['mes_inicio'] ?? null;
        }

        return $dados;
    }

    /**
     * Última palavra do cadastro, chamada pelo Recurso.
     *
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    public static function conferir(string $acao, array $dados, array $atual): ?array
    {
        if ($acao === 'excluir') {
            return null;
        }
        $f = array_merge($atual, $dados);
        $tipo = (string) ($f['tipo'] ?? 'fixo');
        // "Até 25" sem o mês é o mesmo mês (o normalizar grava assim).
        if (($f['dia_fim'] ?? null) !== null && ($f['mes_fim'] ?? null) === null) {
            $f['mes_fim'] = $f['mes_inicio'] ?? null;
        }

        if ($tipo === 'fixo') {
            if (!self::diaValido($f['dia_inicio'] ?? null, $f['mes_inicio'] ?? null)) {
                return ['mensagem' => 'Escolha o dia e o mês do feriado.', 'campo' => 'dia_inicio'];
            }
            if (($f['dia_fim'] ?? null) !== null && !self::diaValido($f['dia_fim'], $f['mes_fim'] ?? null)) {
                return ['mensagem' => 'A data final não existe.', 'campo' => 'dia_fim'];
            }
            if (count(self::diasDoFixo($f)) > self::MAX_DIAS) {
                return ['mensagem' => 'Um feriado vai até ' . self::MAX_DIAS . ' dias. Confira a data final.', 'campo' => 'dia_fim'];
            }
        } elseif ($tipo === 'data') {
            $ini = (string) ($f['data_inicio'] ?? '');
            $fim = (string) ($f['data_fim'] ?? '') ?: $ini;
            if (!self::dataValida($ini)) {
                return ['mensagem' => 'Escolha a data do feriado.', 'campo' => 'data_inicio'];
            }
            if (!self::dataValida($fim) || $fim < $ini) {
                return ['mensagem' => 'A data final é anterior à inicial.', 'campo' => 'data_fim'];
            }
            if (count(self::diasEntre($ini, $fim)) > self::MAX_DIAS) {
                return ['mensagem' => 'Um feriado vai até ' . self::MAX_DIAS . ' dias.', 'campo' => 'data_fim'];
            }
        } elseif ($tipo === 'movel') {
            if (!isset(self::MOVEIS[(string) ($f['movel'] ?? '')])) {
                return ['mensagem' => 'Escolha qual feriado móvel.', 'campo' => 'movel'];
            }
        } else {
            return ['mensagem' => 'Tipo de data desconhecido.', 'campo' => 'tipo'];
        }

        $hi = $f['hora_inicio'] ?? null;
        $hf = $f['hora_fim'] ?? null;
        if (($hi === null) !== ($hf === null)) {
            return ['mensagem' => 'Preencha as duas horas, ou deixe as duas em branco para o dia inteiro.',
                    'campo' => $hi === null ? 'hora_inicio' : 'hora_fim'];
        }
        if ($hi !== null && (self::minutos((string) $hi) === null || self::minutos((string) $hf) === null
                             || self::minutos((string) $hf) <= self::minutos((string) $hi))) {
            return ['mensagem' => 'A hora final precisa ser depois da inicial (no mesmo dia).', 'campo' => 'hora_fim'];
        }

        if (($f['audio_id'] ?? null) !== null
            && !Bd::valor('SELECT id FROM audios WHERE id = ?', [(int) $f['audio_id']])) {
            return ['mensagem' => 'Esse áudio não existe mais. Escolha outro.', 'campo' => 'audio_id'];
        }
        // A condição chamaria o feriado, que chamaria a condição: a
        // chamada ficaria girando.
        if (($f['destino_tipo'] ?? null) === 'condicao') {
            return ['mensagem' => 'Depois do feriado a chamada não pode voltar para uma condição horária: '
                . 'ela consultaria o feriado de novo. Escolha um anúncio, uma fila, um ramal ou desligar.',
                    'campo' => 'destino'];
        }

        return null;
    }

    /** Os minutos desde a meia-noite de "HH:MM" ou "HH:MM:SS". */
    public static function minutos(string $hora): ?int
    {
        if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hora, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return $hora === '24:00' || $hora === '24:00:00' ? 1440 : null;
    }

    /**
     * Os dias de um feriado fixo, MM-DD. Do 31/12 ao 01/01 vira o ano.
     *
     * @return list<string>
     */
    private static function diasDoFixo(array $f): array
    {
        if (!self::diaValido($f['dia_inicio'] ?? null, $f['mes_inicio'] ?? null)) {
            return [];
        }
        // 2024 é bissexto: o 29/02 existe.
        $ini = new \DateTimeImmutable(sprintf('2024-%02d-%02d', (int) $f['mes_inicio'], (int) $f['dia_inicio']));
        $fim = self::diaValido($f['dia_fim'] ?? null, $f['mes_fim'] ?? null)
            ? new \DateTimeImmutable(sprintf('2024-%02d-%02d', (int) $f['mes_fim'], (int) $f['dia_fim']))
            : $ini;
        if ($fim < $ini) {
            $fim = $fim->modify('+1 year');
        }

        $dias = [];
        for ($d = $ini; $d <= $fim && count($dias) <= self::MAX_DIAS; $d = $d->modify('+1 day')) {
            $dias[] = $d->format('m-d');
        }

        return $dias;
    }

    /** @return list<string> */
    private static function diasEntre(string $ini, string $fim): array
    {
        if (!self::dataValida($ini) || !self::dataValida($fim)) {
            return [];
        }
        $dias = [];
        $ate = new \DateTimeImmutable(substr($fim, 0, 10));
        for ($d = new \DateTimeImmutable(substr($ini, 0, 10)); $d <= $ate && count($dias) <= self::MAX_DIAS;
             $d = $d->modify('+1 day')) {
            $dias[] = $d->format('Y-m-d');
        }

        return $dias;
    }

    private static function diaValido(mixed $dia, mixed $mes): bool
    {
        return is_numeric($dia) && is_numeric($mes) && checkdate((int) $mes, (int) $dia, 2024);
    }

    private static function dataValida(string $data): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
