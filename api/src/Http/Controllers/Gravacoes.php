<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Arquivo de gravações.
 *
 * Não existe rota de exclusão aqui, e é de propósito: gravação é prova
 * de atendimento, e apagar uma pela web não deveria ser um clique. O
 * que limpa o disco é a retenção do backup, com prazo definido.
 *
 * As gravações vêm de dois lugares e a tela mostra as duas como uma
 * coisa só: o CDR, que guarda o arquivo de cada chamada, e a tabela
 * gravacoes, onde entram as que não têm CDR próprio — hoje, as
 * conferências.
 */
final class Gravacoes
{
    private const POR_PAGINA = 50;

    private const TIPOS = [
        'wav' => 'audio/wav', 'wav49' => 'audio/wav', 'gsm' => 'audio/x-gsm',
        'ulaw' => 'audio/basic', 'alaw' => 'audio/x-alaw-basic',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'g722' => 'audio/G722',
    ];

    /** GET /api/gravacoes */
    public function listar(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $pagina = max(1, (int) ($p['pagina'] ?? 1));

        [$sql, $args] = $this->consulta($p);

        $total = (int) Bd::valor("SELECT COUNT(*) FROM ({$sql}) t", $args);
        $dados = Bd::todos(
            "{$sql} ORDER BY data DESC LIMIT " . self::POR_PAGINA
            . ' OFFSET ' . (($pagina - 1) * self::POR_PAGINA),
            $args
        );

        $dir = $this->diretorio();
        foreach ($dados as &$g) {
            $caminho = $dir . '/' . ltrim((string) $g['arquivo'], '/');
            $g['existe'] = is_file($caminho);
            $g['tamanho'] = $g['existe'] ? (int) filesize($caminho) : 0;
            $g['duracao'] = (int) $g['duracao'];
        }
        unset($g);

        return Resposta::json($res, [
            'dados' => $dados,
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / self::POR_PAGINA),
            'diretorio' => $dir,
            'disco' => $this->disco($dir),
        ]);
    }

    /** GET /api/gravacoes/arquivo?caminho=… — ouvir ou baixar */
    public function arquivo(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $caminho = $this->resolver((string) ($p['caminho'] ?? ''));

        if ($caminho === null) {
            return Resposta::erro($res, 'Gravação não encontrada no servidor.', 404);
        }

        $baixar = ($p['baixar'] ?? '') === '1';
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        $nome = basename($caminho);

        if ($baixar) {
            Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'apps.gravacao', $nome);
        }

        return Resposta::arquivo(
            $req,
            $res,
            $caminho,
            self::TIPOS[$ext] ?? 'application/octet-stream',
            $baixar,
            $nome
        );
    }

    /** POST /api/gravacoes/pacote — várias gravações num zip só */
    public function pacote(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $pedidos = array_values((array) ($corpo['caminhos'] ?? []));

        // O que o usuário errou vem antes do que falta no servidor.
        if ($pedidos === []) {
            return Resposta::erro($res, 'Selecione ao menos uma gravação.', 422);
        }
        if (count($pedidos) > 200) {
            return Resposta::erro($res, 'São no máximo 200 gravações por vez.', 422);
        }
        if (!class_exists(\ZipArchive::class)) {
            return Resposta::erro($res, 'A extensão zip do PHP não está ativa no servidor.', 500);
        }

        $zipNome = tempnam(sys_get_temp_dir(), 'telium-grav-');
        $zip = new \ZipArchive();
        if ($zip->open($zipNome, \ZipArchive::OVERWRITE) !== true) {
            return Resposta::erro($res, 'Não foi possível montar o pacote no servidor.', 500);
        }

        $incluidas = 0;
        $faltando = [];
        foreach ($pedidos as $pedido) {
            $caminho = $this->resolver((string) $pedido);
            if ($caminho === null) {
                $faltando[] = basename((string) $pedido);
                continue;
            }
            $zip->addFile($caminho, basename($caminho));
            $incluidas++;
        }
        $zip->close();

        if ($incluidas === 0) {
            @unlink($zipNome);

            return Resposta::erro($res, 'Nenhuma das gravações selecionadas está no servidor.', 404);
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'apps.gravacao',
                             "{$incluidas} gravações", ['faltando' => $faltando]);

        $conteudo = (string) file_get_contents($zipNome);
        @unlink($zipNome);

        $corpoRes = $res->getBody();
        $corpoRes->write($conteudo);

        return $res->withBody($corpoRes)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) strlen($conteudo))
            ->withHeader('X-Telium-Incluidas', (string) $incluidas)
            ->withHeader('Content-Disposition',
                'attachment; filename="gravacoes-' . date('Ymd-His') . '.zip"');
    }

    // ------------------------------------------------------------------
    /**
     * A união das duas origens, já com os mesmos nomes de coluna.
     *
     * @return array{0:string, 1:array<int,mixed>}
     */
    private function consulta(array $p): array
    {
        $de = ($p['de'] ?? '') !== '' ? $p['de'] . ' 00:00:00' : null;
        $ate = ($p['ate'] ?? '') !== '' ? $p['ate'] . ' 23:59:59' : null;
        $q = ($p['q'] ?? '') !== '' ? '%' . $p['q'] . '%' : null;
        $direcao = in_array($p['direcao'] ?? '', ['entrada', 'saida', 'interna'], true)
            ? $p['direcao'] : null;

        // ---- gravações de chamada, que vivem no CDR ----
        $ondeCdr = ["c.gravacao IS NOT NULL", "c.gravacao <> ''"];
        $argsCdr = [];
        if ($de !== null)  { $ondeCdr[] = 'c.calldate >= ?'; $argsCdr[] = $de; }
        if ($ate !== null) { $ondeCdr[] = 'c.calldate <= ?'; $argsCdr[] = $ate; }
        if ($q !== null)   { $ondeCdr[] = '(c.src LIKE ? OR c.dst LIKE ?)'; $argsCdr[] = $q; $argsCdr[] = $q; }
        if ($direcao !== null) { $ondeCdr[] = 'c.direcao = ?'; $argsCdr[] = $direcao; }

        $sqlCdr = 'SELECT c.uniqueid, c.gravacao AS arquivo, c.src AS origem, c.dst AS destino,
                          c.direcao, c.billsec AS duracao, c.calldate AS data, c.fila,
                          c.disposition AS estado, NULL AS tags
                     FROM cdr c WHERE ' . implode(' AND ', $ondeCdr);

        // ---- gravações sem CDR próprio (conferências) ----
        $ondeG = ["g.arquivo <> ''"];
        $argsG = [];
        if ($de !== null)  { $ondeG[] = 'g.inicio >= ?'; $argsG[] = $de; }
        if ($ate !== null) { $ondeG[] = 'g.inicio <= ?'; $argsG[] = $ate; }
        if ($q !== null)   { $ondeG[] = '(g.origem LIKE ? OR g.destino LIKE ?)'; $argsG[] = $q; $argsG[] = $q; }
        // Conferência não tem sentido de chamada; um filtro de direção a esconde.
        if ($direcao !== null) { $ondeG[] = '1 = 0'; }

        $sqlG = "SELECT g.uniqueid, g.arquivo, g.origem, g.destino,
                        NULL AS direcao, g.duracao, g.inicio AS data, g.fila,
                        NULL AS estado, g.tags
                   FROM gravacoes g WHERE " . implode(' AND ', $ondeG);

        return ["({$sqlCdr}) UNION ALL ({$sqlG})", [...$argsCdr, ...$argsG]];
    }

    /**
     * Do caminho pedido para um arquivo real dentro do diretório de
     * gravações — ou null. realpath resolve o ".." antes de a gente
     * comparar, que é o ponto: sem isso, "../../etc/passwd" passaria.
     */
    private function resolver(string $pedido): ?string
    {
        $pedido = ltrim(trim($pedido), '/');
        if ($pedido === '' || str_contains($pedido, "\0")) {
            return null;
        }

        $base = realpath($this->diretorio());
        $alvo = realpath($this->diretorio() . '/' . $pedido);

        if ($base === false || $alvo === false || !is_file($alvo)) {
            return null;
        }
        if (!str_starts_with($alvo, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $alvo;
    }

    private function diretorio(): string
    {
        return rtrim((string) Ambiente::get('GRAVACOES_DIR', '/var/spool/asterisk/monitor'), '/');
    }

    private function disco(string $dir): array
    {
        $total = (float) @disk_total_space($dir) ?: 0.0;
        $livre = (float) @disk_free_space($dir) ?: 0.0;

        return [
            'total_gb' => round($total / 1073741824, 1),
            'livre_gb' => round($livre / 1073741824, 1),
            'pct_uso'  => $total > 0 ? (int) round(($total - $livre) / $total * 100) : 0,
        ];
    }
}
