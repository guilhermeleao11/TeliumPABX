<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Pesquisas as Dominio;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/** Resultados da pesquisa de satisfação: resumo, respostas e planilha. */
final class Pesquisas
{
    /** GET /api/pesquisas/resultados?pesquisa=&de=&ate=&fila=&ramal= */
    public function resultados(Request $req, Response $res): Response
    {
        $f = $this->filtros($req);
        if ($f === null) {
            return Resposta::json($res, ['pesquisa' => null]);
        }
        if (is_string($f)) {
            return Resposta::erro($res, $f, 422);
        }

        return Resposta::json($res, ['periodo' => ['de' => $f['de'], 'ate' => $f['ate']]] + Dominio::resultados($f));
    }

    /** GET /api/pesquisas/respostas?…&status=&pagina=&limite= */
    public function respostas(Request $req, Response $res): Response
    {
        $f = $this->filtros($req);
        if ($f === null) {
            return Resposta::json($res, ['dados' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 0]);
        }
        if (is_string($f)) {
            return Resposta::erro($res, $f, 422);
        }

        $q = $req->getQueryParams();
        $limite = max(1, min(200, (int) ($q['limite'] ?? 50)));
        $pagina = max(1, (int) ($q['pagina'] ?? 1));
        $r = Dominio::respostas($f, $limite, ($pagina - 1) * $limite);

        return Resposta::json($res, $r + ['pagina' => $pagina, 'limite' => $limite,
                                          'paginas' => (int) ceil($r['total'] / $limite)]);
    }

    /** GET /api/pesquisas/respostas/csv — a mesma lista, inteira, para o Excel. */
    public function csv(Request $req, Response $res): Response
    {
        $f = $this->filtros($req);
        if (!is_array($f)) {
            return Resposta::erro($res, is_string($f) ? $f : 'Nenhuma pesquisa cadastrada.', 422);
        }

        $linhas = Dominio::respostas($f, 100000, 0)['dados'];
        $status = ['respondida' => 'Respondida', 'sem_resposta' => 'Não respondeu',
                   'invalida' => 'Tecla inválida', 'desligou' => 'Desligou no meio'];

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Data', 'Origem', 'Fila', 'Ramal', 'Atendente', 'Situação', 'Nota', 'Escala',
                      'Satisfação (0-100)', 'Avaliação', 'Chamada'], ';', '"', '');
        // Nome que comece com = + - @ vira fórmula no Excel de quem abrir.
        $celula = static fn ($v): string => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0])
            && preg_match('/^[+-]?[0-9]+$/', $v) !== 1 ? "'" . $v : (string) $v;
        foreach ($linhas as $l) {
            $escala = $l['nota_min'] !== null
                ? "{$l['nota_min']} a {$l['nota_max']} (" . ($l['sentido'] === 'menor_melhor' ? "{$l['nota_min']}" : "{$l['nota_max']}")
                  . ' é a melhor)'
                : '';
            fputcsv($fh, array_map($celula, [
                $l['criado_em'], $l['origem'], trim(($l['fila'] ?? '') . ' ' . ($l['fila_nome'] ?? '')),
                $l['ramal'], $l['atendente'] ?? $l['agente'], $status[$l['status']] ?? $l['status'],
                $l['nota'], $escala, $l['satisfacao'] !== null ? str_replace('.', ',', (string) $l['satisfacao']) : '',
                $l['avaliacao'], $l['uniqueid'],
            ]), ';', '"', '');
        }
        rewind($fh);
        $conteudo = (string) stream_get_contents($fh);
        fclose($fh);

        Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'apps.pesquisas',
                             count($linhas) . ' respostas', ['pesquisa' => $f['pesquisa']],
                             $req->getServerParams()['REMOTE_ADDR'] ?? null);

        $res->getBody()->write($conteudo);

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="pesquisa-' . (int) $f['pesquisa']
                . '-' . $f['de'] . '-a-' . $f['ate'] . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Os filtros da URL, conferidos. Sem pesquisa escolhida vale a primeira
     * ativa (ou a primeira de todas); sem período, os últimos 30 dias.
     *
     * @return array<string,mixed>|string|null  string = erro; null = nenhuma pesquisa existe
     */
    private function filtros(Request $req): array|string|null
    {
        $q = $req->getQueryParams();
        $pesquisa = (int) ($q['pesquisa'] ?? 0);
        if ($pesquisa <= 0) {
            $pesquisa = (int) (Bd::valor('SELECT id FROM pesquisas ORDER BY ativo DESC, id LIMIT 1') ?: 0);
            if ($pesquisa === 0) {
                return null;
            }
        }

        $data = static fn (string $v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
            && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
        $de = (string) ($q['de'] ?? '') ?: date('Y-m-d', strtotime('-29 days'));
        $ate = (string) ($q['ate'] ?? '') ?: date('Y-m-d');
        if (!$data($de) || !$data($ate)) {
            return 'Período em formato inválido (AAAA-MM-DD).';
        }
        if ($de > $ate) {
            return 'O início do período vem depois do fim.';
        }

        return ['pesquisa' => $pesquisa, 'de' => $de, 'ate' => $ate,
                'fila' => trim((string) ($q['fila'] ?? '')), 'ramal' => trim((string) ($q['ramal'] ?? '')),
                'status' => (string) ($q['status'] ?? '')];
    }
}
