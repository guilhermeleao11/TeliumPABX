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
     * @param array<string,array<string,mixed>> $regras validação por campo:
     *        padrao (regex), mensagem, min, max, email, em (valores aceitos)
     * @param string[]  $unicas    colunas com índice único, para traduzir o
     *                             erro 1062 apontando o campo certo
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
        private readonly array $regras = [],
        private readonly array $unicas = [],
        /**
         * Ajuste final antes de gravar: recebe os campos enviados e a
         * linha atual, devolve os campos a gravar. Serve para o que uma
         * opção implica em outras — marcar WebRTC num ramal liga DTLS,
         * ICE, AVPF e rtcp-mux, e o cadastro precisa mostrar isso, não
         * só o arquivo gerado.
         *
         * @var (callable(array<string,mixed>, array<string,mixed>): array<string,mixed>)|null
         */
        private $normalizar = null,
    ) {
    }

    /** Marcador interno: este "" deve ir para o banco como veio. */
    private const MANTER_VAZIO = "\0manter-vazio";

    /** @var array<string,array{nulo:bool, texto:bool, padrao:mixed}>|null */
    private ?array $meta = null;

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

        $problema = $this->validar($dados, true);
        if ($problema !== null) {
            return Resposta::erro($res, $problema['mensagem'], 422, ['campo' => $problema['campo']]);
        }

        if ($this->normalizar !== null) {
            $dados = ($this->normalizar)($dados, []);
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

        $problema = $this->validar($dados, false);
        if ($problema !== null) {
            return Resposta::erro($res, $problema['mensagem'], 422, ['campo' => $problema['campo']]);
        }

        if ($this->normalizar !== null) {
            $dados = ($this->normalizar)($dados, $atual);
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
    /**
     * Confere os campos enviados contra as regras declaradas na rota.
     *
     * Na criação, um campo obrigatório ausente é erro; na edição, campo
     * ausente quer dizer "não mexe nele", então só se valida o que veio.
     *
     * @return array{campo:string, mensagem:string}|null
     */
    private function validar(array $dados, bool $criando): ?array
    {
        foreach ($this->regras as $campo => $regra) {
            $rotulo = $regra['rotulo'] ?? $campo;
            $presente = array_key_exists($campo, $dados);
            $valor = $presente ? (string) ($dados[$campo] ?? '') : '';

            if (!$presente) {
                if ($criando && ($regra['obrigatorio'] ?? false)) {
                    return ['campo' => $campo, 'mensagem' => "O campo \"{$rotulo}\" é obrigatório."];
                }
                continue;
            }

            // Vazio só é problema quando o campo é obrigatório: nas demais
            // colunas, apagar o conteúdo é uma edição legítima.
            if (trim($valor) === '') {
                if ($regra['obrigatorio'] ?? false) {
                    return ['campo' => $campo, 'mensagem' => "O campo \"{$rotulo}\" não pode ficar vazio."];
                }
                continue;
            }

            if (isset($regra['min']) && mb_strlen($valor) < (int) $regra['min']) {
                return ['campo' => $campo, 'mensagem' => $regra['mensagem']
                    ?? "O campo \"{$rotulo}\" precisa de pelo menos {$regra['min']} caracteres."];
            }
            if (isset($regra['max']) && mb_strlen($valor) > (int) $regra['max']) {
                return ['campo' => $campo,
                        'mensagem' => "O campo \"{$rotulo}\" passa de {$regra['max']} caracteres."];
            }
            if (($regra['email'] ?? false) && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                return ['campo' => $campo, 'mensagem' => 'E-mail em formato inválido.'];
            }
            if (isset($regra['em']) && !in_array($valor, (array) $regra['em'], true)) {
                return ['campo' => $campo,
                        'mensagem' => $regra['mensagem'] ?? "Valor não aceito em \"{$rotulo}\"."];
            }
            if (isset($regra['padrao']) && preg_match($regra['padrao'], $valor) !== 1) {
                return ['campo' => $campo,
                        'mensagem' => $regra['mensagem'] ?? "O campo \"{$rotulo}\" está em formato inválido."];
            }
        }

        return null;
    }

    private function extrair(array $corpo): array
    {
        $dados = [];
        foreach ($this->colunas as $coluna) {
            if (!array_key_exists($coluna, $corpo)) {
                continue;
            }

            $valor = $corpo[$coluna];
            if (is_bool($valor)) {
                $valor = (int) $valor;
            }

            // Um <select> sem escolha e um campo de número em branco chegam
            // como "". Em coluna numérica ou de data isso não é "vazio", é
            // "não informado" — e o MariaDB em modo estrito recusa a string.
            if ($valor === '') {
                $valor = $this->vazioVira($coluna);
                if ($valor === self::MANTER_VAZIO) {
                    $valor = '';
                }
            }

            $dados[$coluna] = $valor;
        }

        return $dados;
    }

    /**
     * No que um "" se transforma nesta coluna: NULL quando ela aceita,
     * o valor padrão quando é obrigatória mas tem um, e nada quando é
     * texto — aí "" é um valor legítimo.
     */
    private function vazioVira(string $coluna): mixed
    {
        $m = $this->colunasDaTabela()[$coluna] ?? null;

        if ($m === null || $m['texto']) {
            return self::MANTER_VAZIO;
        }
        if ($m['nulo']) {
            return null;
        }

        return $m['padrao'] ?? self::MANTER_VAZIO;
    }

    /**
     * Tipo e nulidade de cada coluna, lidos uma vez por requisição.
     *
     * @return array<string,array{nulo:bool, texto:bool, padrao:mixed}>
     */
    private function colunasDaTabela(): array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }

        $this->meta = [];
        foreach (Bd::todos("SHOW COLUMNS FROM `{$this->tabela}`") as $c) {
            $tipo = strtolower((string) ($c['Type'] ?? ''));
            $this->meta[(string) $c['Field']] = [
                'nulo'   => strtoupper((string) ($c['Null'] ?? '')) === 'YES',
                'texto'  => preg_match('/char|text|blob|enum|set|json/', $tipo) === 1,
                'padrao' => $c['Default'],
            ];
        }

        return $this->meta;
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

        if ($codigo === 1062) {
            // O índice único costuma se chamar como a coluna, mas nem sempre;
            // a rota declara quais colunas são únicas para a mensagem servir.
            $campo = null;
            foreach ($this->unicas as $coluna) {
                if (str_contains($texto, $coluna)) {
                    $campo = $coluna;
                    break;
                }
            }
            $campo ??= $this->unicas[0] ?? null;

            return Resposta::erro(
                $res,
                $campo === null
                    ? 'Já existe um registro com esse identificador'
                    : "Já existe outro registro com esse valor em \"{$campo}\".",
                409,
                $campo === null ? [] : ['campo' => $campo]
            );
        }

        return match ($codigo) {
            1452 => Resposta::erro($res,
                'Um dos vínculos aponta para um cadastro que não existe. '
                . 'Cadastre-o antes (por exemplo, o tronco de uma rota de saída).', 422),
            1451 => Resposta::erro($res,
                'Este registro está vinculado a outro cadastro e não pode ser excluído.', 409),
            default => Resposta::erro($res, 'Não foi possível gravar: ' . $texto, 400),
        };
    }
}
