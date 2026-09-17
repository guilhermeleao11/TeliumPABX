<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Grupos de horário e condições horárias.
 *
 * As faixas do grupo chegam inteiras, como as teclas da URA: a tela
 * monta o grupo e as faixas na mesma gaveta.
 */
final class Horarios
{
    /** GET /api/grupos-horario/{id}/faixas */
    public function faixas(Request $req, Response $res, array $args): Response
    {
        $grupo = Bd::um('SELECT * FROM grupos_horario WHERE id = ?', [$args['id']]);
        if ($grupo === null) {
            return Resposta::erro($res, 'Grupo de horário não encontrado', 404);
        }

        return Resposta::json($res, [
            'grupo' => $grupo,
            'faixas' => Bd::todos(
                'SELECT * FROM grupo_horario_faixas WHERE grupo_id = ? ORDER BY ordem, id',
                [$grupo['id']]
            ),
        ]);
    }

    /** PUT /api/grupos-horario/{id}/faixas — troca a lista inteira */
    public function salvarFaixas(Request $req, Response $res, array $args): Response
    {
        $grupo = Bd::um('SELECT * FROM grupos_horario WHERE id = ?', [$args['id']]);
        if ($grupo === null) {
            return Resposta::erro($res, 'Grupo de horário não encontrado', 404);
        }

        $lista = array_values((array) (((array) $req->getParsedBody())['faixas'] ?? []));

        foreach ($lista as $i => $f) {
            $erro = $this->conferir($f, $i);
            if ($erro !== null) {
                return Resposta::erro($res, $erro['mensagem'], 422, ['campo' => $erro['campo']]);
            }
        }

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            Bd::executar('DELETE FROM grupo_horario_faixas WHERE grupo_id = ?', [$grupo['id']]);

            foreach ($lista as $i => $f) {
                Bd::executar(
                    'INSERT INTO grupo_horario_faixas
                       (grupo_id, hora_inicio, hora_fim, dia_semana_inicio, dia_semana_fim,
                        dia_mes_inicio, dia_mes_fim, mes_inicio, mes_fim, ordem, dias, dia_mes, mes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $grupo['id'],
                        $this->hora($f['hora_inicio'] ?? null),
                        $this->hora($f['hora_fim'] ?? null),
                        $this->numero($f['dia_semana_inicio'] ?? null),
                        $this->numero($f['dia_semana_fim'] ?? null),
                        $this->numero($f['dia_mes_inicio'] ?? null),
                        $this->numero($f['dia_mes_fim'] ?? null),
                        $this->numero($f['mes_inicio'] ?? null),
                        $this->numero($f['mes_fim'] ?? null),
                        ($i + 1) * 10,
                        // Os dias da semana como LISTA. O par
                        // início/fim só sabe dizer faixa contínua, e
                        // "segunda, quarta e sexta" ou "sábado e
                        // domingo" não são contínuos — este último nem
                        // como "sáb-dom", porque a semana começa no
                        // domingo. O GotoIfTime aceita "sat,sun" sem
                        // problema; o formulário é que não deixava.
                        $this->diasDaSemana($f['dias'] ?? null),
                        '*', '*',
                    ]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            return Resposta::erro($res, 'Não foi possível gravar as faixas: ' . $e->getMessage(), 400);
        }

        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'apps.grupohorario',
                             (string) $grupo['nome'], ['faixas' => count($lista)]);

        return Resposta::json($res, ['ok' => true, 'faixas' => count($lista)]);
    }

    /**
     * GET /api/condicoes-horarias/{id}/agora
     *
     * Responde a pergunta que a tela faz: neste instante, esta condição
     * manda para dentro ou para fora? Inclui a marca de "forçado", que o
     * código *27 grava na base do Asterisk.
     */
    public function agora(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM condicoes_horarias WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Condição não encontrada', 404);
        }

        $faixas = Bd::todos(
            'SELECT * FROM grupo_horario_faixas WHERE grupo_id = ? ORDER BY ordem, id',
            [$c['grupo_horario_id']]
        );

        $forcado = $this->forcado((int) $c['id']);
        $dentro = $forcado === null
            ? $this->dentroDeAlguma($faixas, new \DateTimeImmutable())
            : $forcado === 'aberto';

        return Resposta::json($res, [
            'dentro' => $dentro,
            'forcado' => $forcado,
            'faixas' => count($faixas),
            'agora' => date('Y-m-d H:i:s'),
        ]);
    }

    /** POST /api/condicoes-horarias/{id}/forcar — {modo: aberto|fechado|auto} */
    public function forcar(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT * FROM condicoes_horarias WHERE id = ?', [$args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Condição não encontrada', 404);
        }

        $modo = (string) (((array) $req->getParsedBody())['modo'] ?? 'auto');
        if (!in_array($modo, ['aberto', 'fechado', 'auto'], true)) {
            return Resposta::erro($res, 'Modo inválido. Use aberto, fechado ou auto.', 422);
        }

        // A marca vive na base do Asterisk, que é quem o dialplan consulta.
        try {
            $ami = Ami::compartilhada();
            $resposta = $modo === 'auto'
                ? $ami->acao(['Action' => 'DBDel', 'Family' => 'condicao', 'Key' => (string) $c['id']])
                : $ami->acao(['Action' => 'DBPut', 'Family' => 'condicao',
                              'Key' => (string) $c['id'], 'Val' => $modo]);
        } catch (\Throwable $e) {
            return Resposta::erro(
                $res,
                'Sem comunicação com o Asterisk: a condição continua seguindo o relógio.',
                503
            );
        }

        if (str_contains(strtolower($resposta), 'error')
            && !str_contains(strtolower($resposta), 'does not exist')) {
            return Resposta::erro($res, 'O Asterisk recusou a mudança: ' . trim($resposta), 502);
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'apps.condicoes',
                             (string) $c['nome'], ['forcar' => $modo]);

        return Resposta::json($res, ['modo' => $modo]);
    }

    // ------------------------------------------------------------------
    /** @return array{campo:string, mensagem:string}|null */
    private function conferir(array $f, int $i): ?array
    {
        $ini = $this->hora($f['hora_inicio'] ?? null);
        $fim = $this->hora($f['hora_fim'] ?? null);

        if (($ini === null) !== ($fim === null)) {
            return ['campo' => "hora-{$i}",
                    'mensagem' => 'Na faixa ' . ($i + 1) . ', informe a hora de início e a de término — '
                                . 'ou deixe as duas em branco para valer o dia inteiro.'];
        }

        $pares = [
            'dia da semana' => ['dia_semana_inicio', 'dia_semana_fim', 0, 6],
            'dia do mês'    => ['dia_mes_inicio', 'dia_mes_fim', 1, 31],
            'mês'           => ['mes_inicio', 'mes_fim', 1, 12],
        ];

        foreach ($pares as $rotulo => [$a, $b, $min, $max]) {
            $de = $this->numero($f[$a] ?? null);
            $ate = $this->numero($f[$b] ?? null);

            foreach ([$de, $ate] as $v) {
                if ($v !== null && ($v < $min || $v > $max)) {
                    return ['campo' => "{$a}-{$i}",
                            'mensagem' => 'Na faixa ' . ($i + 1) . ", o {$rotulo} está fora do intervalo."];
                }
            }
            if ($de === null && $ate !== null) {
                return ['campo' => "{$a}-{$i}",
                        'mensagem' => 'Na faixa ' . ($i + 1) . ", informe o início do {$rotulo} também."];
            }
        }

        return null;
    }

    /** Alguma faixa cobre este instante? */
    private function dentroDeAlguma(array $faixas, \DateTimeImmutable $quando): bool
    {
        foreach ($faixas as $f) {
            if ($this->cobre($f, $quando)) {
                return true;
            }
        }

        return false;
    }

    private function cobre(array $f, \DateTimeImmutable $q): bool
    {
        $emFaixa = static function (?int $valor, mixed $de, mixed $ate, int $volta): bool {
            if ($de === null || $de === '') {
                return true;
            }
            $de = (int) $de;
            $ate = ($ate === null || $ate === '') ? $de : (int) $ate;

            // Faixas que viram a volta (sex a seg, nov a fev) são válidas.
            return $de <= $ate
                ? ($valor >= $de && $valor <= $ate)
                : ($valor >= $de || $valor <= $ate);
        };

        if (($f['hora_inicio'] ?? null) !== null && ($f['hora_fim'] ?? null) !== null) {
            $agora = (int) $q->format('Hi');
            $de = (int) str_replace(':', '', substr((string) $f['hora_inicio'], 0, 5));
            $ate = (int) str_replace(':', '', substr((string) $f['hora_fim'], 0, 5));

            $dentro = $de <= $ate ? ($agora >= $de && $agora <= $ate) : ($agora >= $de || $agora <= $ate);
            if (!$dentro) {
                return false;
            }
        }

        return $emFaixa((int) $q->format('w'), $f['dia_semana_inicio'] ?? null, $f['dia_semana_fim'] ?? null, 7)
            && $emFaixa((int) $q->format('j'), $f['dia_mes_inicio'] ?? null, $f['dia_mes_fim'] ?? null, 31)
            && $emFaixa((int) $q->format('n'), $f['mes_inicio'] ?? null, $f['mes_fim'] ?? null, 12);
    }

    private function forcado(int $id): ?string
    {
        $saida = Ami::tentarComando("database get condicao {$id}");
        if ($saida === null || preg_match('/Value:\s*(\w+)/i', $saida, $m) !== 1) {
            return null;
        }

        return in_array($m[1], ['aberto', 'fechado'], true) ? $m[1] : null;
    }

    /** "8:30" e "08:30:00" viram "08:30:00"; o resto vira null. */
    private function hora(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) === 1
            ? sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2])
            : null;
    }

    /**
     * A lista de dias da semana no formato do GotoIfTime.
     *
     * Aceita "*" (todo dia) ou uma lista de três letras em inglês, que
     * é o que o Asterisk entende. O que vier fora disso é descartado em
     * vez de virar dialplan: nome de dia é coisa que o formulário
     * escolhe, não que o usuário digita.
     */
    private function diasDaSemana(mixed $bruto): string
    {
        $validos = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        $lista = is_array($bruto) ? $bruto : explode(',', (string) $bruto);
        $lista = array_values(array_unique(array_filter(
            array_map(static fn ($d): string => strtolower(trim((string) $d)), $lista),
            static fn (string $d): bool => in_array($d, $validos, true)
        )));

        // Ordem da semana, e não a que o usuário clicou: "mon,sun" e
        // "sun,mon" são a mesma faixa e devem sair iguais no arquivo.
        usort($lista, static fn (string $a, string $b): int
            => array_search($a, $validos, true) <=> array_search($b, $validos, true));

        return ($lista === [] || count($lista) === 7) ? '*' : implode(',', $lista);
    }

    private function numero(mixed $v): ?int
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : (int) $v;
    }
}
