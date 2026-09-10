<?php
declare(strict_types=1);

namespace Telium\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Recurso REST genérico sobre uma tabela.
 *
 * Evita repetir o mesmo CRUD uma dúzia de vezes. Cada rota declara a
 * tabela, as colunas graváveis e como filtrar; o resto é igual.
 */
final class Recurso
{
    /**
     * @param string[]  $colunas   colunas que o cliente pode gravar
     * @param string[]  $busca     colunas varridas pela busca textual (?q=)
     * @param string[]  $filtros   colunas filtráveis por igualdade (?coluna=valor)
     * @param string[]  $ocultas   colunas nunca devolvidas (senhas, segredos)
     * @param bool      $afetaAsterisk marca configuração pendente ao gravar
     */
    public function __construct(
        private readonly string $tabela,
        private readonly array $colunas,
        private readonly string $ordem = 'id',
        private readonly array $busca = [],
        private readonly array $filtros = [],
        private readonly array $ocultas = [],
        private readonly bool $afetaAsterisk = false,
        private readonly string $modulo = '',
    ) {
    }

    // ------------------------------------------------------------------
    public function listar(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $where = [];
        $args = [];

        if (($p['q'] ?? '') !== '' && $this->busca !== []) {
            $partes = array_map(fn (string $c): string => "`{$c}` LIKE ?", $this->busca);
            $where[] = '(' . implode(' OR ', $partes) . ')';
            foreach ($this->busca as $_) {
                $args[] = '%' . $p['q'] . '%';
            }
        }

        foreach ($this->filtros as $coluna) {
            if (($p[$coluna] ?? '') !== '') {
                $where[] = "`{$coluna}` = ?";
                $args[] = $p[$coluna];
            }
        }

        $sql = "SELECT * FROM `{$this->tabela}`"
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . " ORDER BY {$this->ordem}";

        $limite = min(500, max(1, (int) ($p['limite'] ?? 200)));
        $pagina = max(1, (int) ($p['pagina'] ?? 1));
        $total = (int) Bd::valor(
            "SELECT COUNT(*) FROM `{$this->tabela}`"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)),
            $args
        );

        $linhas = Bd::todos($sql . ' LIMIT ' . $limite . ' OFFSET ' . (($pagina - 1) * $limite), $args);

        return Resposta::json($res, [
            'dados'  => array_map($this->limpar(...), $linhas),
            'total'  => $total,
            'pagina' => $pagina,
            'limite' => $limite,
        ]);
    }

    public function obter(Request $req, Response $res, array $args): Response
    {
        $linha = Bd::um("SELECT * FROM `{$this->tabela}` WHERE id = ?", [$args['id']]);

        return $linha === null
            ? Resposta::erro($res, 'Registro não encontrado', 404)
            : Resposta::json($res, $this->limpar($linha));
    }

    public function criar(Request $req, Response $res): Response
    {
        $dados = $this->extrair((array) $req->getParsedBody());
        if ($dados === []) {
            return Resposta::erro($res, 'Nenhum campo válido enviado', 422);
        }

        $campos = array_keys($dados);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->tabela,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $campos)),
            implode(', ', array_fill(0, count($campos), '?'))
        );

        try {
            Bd::executar($sql, array_values($dados));
        } catch (\PDOException $e) {
            return $this->erroBanco($res, $e);
        }

        $id = (int) Bd::conexao()->lastInsertId();
        $this->depoisDeGravar($req, 'criar', (string) $id, $dados);

        return Resposta::json($res, $this->limpar(
            Bd::um("SELECT * FROM `{$this->tabela}` WHERE id = ?", [$id]) ?? []
        ), 201);
    }

    public function atualizar(Request $req, Response $res, array $args): Response
    {
        $atual = Bd::um("SELECT * FROM `{$this->tabela}` WHERE id = ?", [$args['id']]);
        if ($atual === null) {
            return Resposta::erro($res, 'Registro não encontrado', 404);
        }

        $dados = $this->extrair((array) $req->getParsedBody());
        if ($dados === []) {
            return Resposta::erro($res, 'Nenhum campo válido enviado', 422);
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($dados)));

        try {
            Bd::executar(
                "UPDATE `{$this->tabela}` SET {$sets} WHERE id = ?",
                [...array_values($dados), $args['id']]
            );
        } catch (\PDOException $e) {
            return $this->erroBanco($res, $e);
        }

        $this->depoisDeGravar($req, 'editar', (string) $args['id'], $dados);

        return Resposta::json($res, $this->limpar(
            Bd::um("SELECT * FROM `{$this->tabela}` WHERE id = ?", [$args['id']]) ?? []
        ));
    }

    public function remover(Request $req, Response $res, array $args): Response
    {
        $atual = Bd::um("SELECT * FROM `{$this->tabela}` WHERE id = ?", [$args['id']]);
        if ($atual === null) {
            return Resposta::erro($res, 'Registro não encontrado', 404);
        }

        try {
            Bd::executar("DELETE FROM `{$this->tabela}` WHERE id = ?", [$args['id']]);
        } catch (\PDOException $e) {
            return $this->erroBanco($res, $e);
        }

        $this->depoisDeGravar($req, 'excluir', (string) $args['id'], []);

        return Resposta::json($res, ['removido' => true]);
    }

    // ------------------------------------------------------------------
    /** Mantém apenas as colunas declaradas como graváveis. */
    private function extrair(array $corpo): array
    {
        $dados = [];
        foreach ($this->colunas as $coluna) {
            if (!array_key_exists($coluna, $corpo)) {
                continue;
            }
            $valor = $corpo[$coluna];
            $dados[$coluna] = is_bool($valor) ? (int) $valor : $valor;
        }
        return $dados;
    }

    /** Remove colunas sensíveis da resposta. */
    private function limpar(array $linha): array
    {
        foreach ($this->ocultas as $coluna) {
            unset($linha[$coluna]);
        }
        return $linha;
    }

    private function depoisDeGravar(Request $req, string $acao, string $objeto, array $dados): void
    {
        Auditoria::registrar(
            $req->getAttribute('usuario'),
            $acao,
            $this->modulo ?: $this->tabela,
            $objeto,
            array_diff_key($dados, array_flip($this->ocultas)),
            $req->getServerParams()['REMOTE_ADDR'] ?? null
        );

        if ($this->afetaAsterisk) {
            Bd::executar(
                "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
                 ON DUPLICATE KEY UPDATE valor = '1'"
            );
        }
    }

    private function erroBanco(Response $res, \PDOException $e): Response
    {
        $codigo = $e->errorInfo[1] ?? 0;
        $texto = $e->errorInfo[2] ?? $e->getMessage();

        // 1364: coluna obrigatória sem valor. Dizer QUAL coluna evita
        // devolver ao usuário uma mensagem de banco sem tradução.
        if ($codigo === 1364 && preg_match("/Field '([^']+)'/", $texto, $m) === 1) {
            return Resposta::erro($res, "O campo obrigatório \"{$m[1]}\" não foi informado", 422,
                ['campo' => $m[1]]);
        }

        if ($codigo === 1048 && preg_match("/Column '([^']+)'/", $texto, $m) === 1) {
            return Resposta::erro($res, "O campo \"{$m[1]}\" não pode ficar vazio", 422,
                ['campo' => $m[1]]);
        }

        return match ($codigo) {
            1062 => Resposta::erro($res, 'Já existe um registro com esse identificador', 409),
            1452 => Resposta::erro($res,
                'Um dos vínculos aponta para um cadastro que não existe. '
                . 'Cadastre-o antes (por exemplo, o tronco de uma rota de saída).', 422),
            1451 => Resposta::erro($res,
                'Este registro está vinculado a outro cadastro e não pode ser excluído.', 409),
            default => Resposta::erro($res, 'Não foi possível gravar: ' . $texto, 400),
        };
    }
}
