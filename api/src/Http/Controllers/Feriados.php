<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Feriados as Calendario;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Feriados: o que não é o cadastro em si (este é um Recurso).
 *
 * As datas de cada feriado no ano, para a tela mostrar "Páscoa: 05/04"
 * sem calcular nada no navegador, e a carga dos feriados nacionais.
 */
final class Feriados
{
    /** GET /api/feriados/datas?ano=2026 — os dias de cada feriado no ano, e o de hoje */
    public function datas(Request $req, Response $res): Response
    {
        $ano = (int) ($req->getQueryParams()['ano'] ?? date('Y'));
        if ($ano < 2000 || $ano > 2100) {
            $ano = (int) date('Y');
        }

        $datas = [];
        foreach (Bd::todos('SELECT * FROM feriados ORDER BY id') as $f) {
            $datas[(string) $f['id']] = Calendario::datas($f, $ano);
        }
        $deHoje = Calendario::deAgora(new \DateTimeImmutable());
        $deHoje = $deHoje === null ? null : (int) $deHoje['id'];

        return Resposta::json($res, ['ano' => $ano, 'datas' => $datas, 'hoje' => $deHoje,
                                     'moveis' => array_map(static fn (array $m): string => $m['rotulo'], Calendario::MOVEIS)]);
    }

    /**
     * POST /api/feriados/nacionais — cadastra os feriados nacionais que
     * ainda não estão na lista (pelo nome). Entram sem áudio: até alguém
     * escolher o áudio de cada um, o feriado segue o "fora do horário".
     */
    public function nacionais(Request $req, Response $res): Response
    {
        $existentes = array_map(static fn (array $f): string => mb_strtolower(trim((string) $f['nome'])),
                                Bd::todos('SELECT nome FROM feriados'));
        $criados = [];
        Bd::transacao(function () use ($existentes, &$criados): void {
            foreach (Calendario::NACIONAIS as $f) {
                if (in_array(mb_strtolower($f['nome']), $existentes, true)) {
                    continue;
                }
                Bd::executar(
                    'INSERT INTO feriados (nome, tipo, dia_inicio, mes_inicio, dia_fim, mes_fim, movel, ativo)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$f['nome'], $f['tipo'], $f['dia_inicio'] ?? null, $f['mes_inicio'] ?? null,
                     $f['dia_fim'] ?? null, $f['mes_fim'] ?? null, $f['movel'] ?? null, $f['ativo'] ?? 1]
                );
                $criados[] = $f['nome'];
            }
            if ($criados !== []) {
                Bd::executar("INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
                              ON DUPLICATE KEY UPDATE valor = '1'");
            }
        });

        if ($criados !== []) {
            Auditoria::registrar($req->getAttribute('usuario'), 'criar', 'apps.feriados',
                                 'feriados nacionais', ['criados' => $criados],
                                 $req->getServerParams()['REMOTE_ADDR'] ?? null);
        }

        return Resposta::json($res, ['criados' => $criados]);
    }
}
