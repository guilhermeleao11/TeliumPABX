<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Http\Recurso;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Ramais em planilha: modelo, exportação e cadastro em lote.
 *
 * A importação não tem regra própria. Cada linha vira o mesmo pedido que
 * a tela de ramais faz, e passa pelo mesmo Recurso: validação, número
 * único, o que o WebRTC liga junto, auditoria e "configuração pendente".
 * Uma regra nova no cadastro vale aqui sem ninguém lembrar deste arquivo.
 *
 * A prévia roda tudo isso dentro de uma transação desfeita no fim: o que
 * ela diz que vai acontecer é o que acontece, porque é o mesmo caminho.
 */
final class RamaisCsv
{
    /** As colunas da planilha, na ordem do modelo. */
    private const COLUNAS = [
        'numero', 'nome', 'setor', 'email', 'senha_sip', 'webrtc',
        'voicemail', 'vm_senha', 'vm_email',
        'perm_local', 'perm_celular', 'perm_ddd', 'perm_ddi',
        'gravar', 'callgroup', 'pickupgroup', 'cid_pseudo', 'did', 'max_saidas',
        'contexto', 'ativo',
    ];

    /** Aceitam sim/não, s/n, 1/0, true/false, x. */
    private const SIM_NAO = ['webrtc', 'voicemail', 'vm_email', 'perm_local', 'perm_celular', 'perm_ddd', 'perm_ddi', 'ativo'];

    /** Nunca saem numa exportação: a planilha circula por e-mail. */
    private const SEGREDOS = ['senha_sip', 'vm_senha'];

    private const MAX_LINHAS = 2000;
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly Recurso $ramais)
    {
    }

    /** GET /api/ramais/csv/modelo */
    public function modelo(Request $req, Response $res): Response
    {
        $exemplos = [
            ['2001', 'Recepção', 'Atendimento', 'recepcao@empresa.com.br', '', '0', '1', '', '1',
             '1', '1', '1', '0', 'nao', '1', '1', '', '', '', 'interno', '1'],
            ['2002', 'Maria Souza', 'Comercial', 'maria@empresa.com.br', '', '1', '1', '', '0',
             '1', '1', '1', '0', 'ambas', '1', '1', '1140042002', '', '2', 'interno', '1'],
        ];

        return self::planilha($res, 'modelo-ramais.csv', self::COLUNAS, $exemplos);
    }

    /** GET /api/ramais/csv — todos os ramais, sem senha nenhuma. */
    public function exportar(Request $req, Response $res): Response
    {
        $linhas = [];
        foreach (Bd::todos('SELECT * FROM ramais ORDER BY CAST(numero AS UNSIGNED), numero') as $r) {
            $linhas[] = array_map(
                static fn (string $c): string => in_array($c, self::SEGREDOS, true) ? '' : (string) ($r[$c] ?? ''),
                self::COLUNAS
            );
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'exportar', 'conn.ramais',
                             count($linhas) . ' ramais', [], $req->getServerParams()['REMOTE_ADDR'] ?? null);

        return self::planilha($res, 'ramais-' . date('Ymd-His') . '.csv', self::COLUNAS, $linhas);
    }

    /**
     * POST /api/ramais/csv {conteudo, simular, atualizar}
     *
     * simular = true: diz o que cada linha faria, sem gravar.
     * atualizar = true: ramal que já existe é atualizado; sem isso, fica
     * como está e a linha aparece como "já existe".
     */
    public function importar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $texto = (string) ($corpo['conteudo'] ?? '');
        $simular = filter_var($corpo['simular'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $atualizar = filter_var($corpo['atualizar'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (trim($texto) === '') {
            return Resposta::erro($res, 'O arquivo está vazio.', 422);
        }
        if (strlen($texto) > self::MAX_BYTES) {
            return Resposta::erro($res, 'O arquivo passa de 2 MB. Divida a planilha em partes.', 422);
        }

        [$cabecalho, $linhas, $erro] = self::ler($texto);
        if ($erro !== null) {
            return Resposta::erro($res, $erro, 422);
        }
        if (count($linhas) > self::MAX_LINHAS) {
            return Resposta::erro($res, 'São no máximo ' . self::MAX_LINHAS . ' ramais por arquivo.', 422);
        }

        $podeEditar = Permissoes::podeAcao((array) $req->getAttribute('caps'), 'editar');
        $existentes = array_column(Bd::todos('SELECT id, numero FROM ramais'), 'id', 'numero');

        $resultado = [];
        $vistos = [];
        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            foreach ($linhas as [$nLinha, $celulas]) {
                $dados = [];
                foreach ($cabecalho as $i => $coluna) {
                    $valor = self::valor($coluna, $celulas[$i] ?? '');
                    // Célula vazia é "não mexer": na criação vale o padrão
                    // do cadastro; na atualização, fica o que já estava.
                    if ($valor !== '') {
                        $dados[$coluna] = $valor;
                    }
                }

                $numero = (string) ($dados['numero'] ?? '');
                $item = ['linha' => $nLinha, 'numero' => $numero, 'nome' => (string) ($dados['nome'] ?? '')];

                $simNao = array_values(array_filter(
                    array_intersect(array_keys($dados), self::SIM_NAO),
                    static fn (string $c): bool => !in_array($dados[$c], ['0', '1'], true)
                ));
                if ($simNao !== []) {
                    $resultado[] = $item + ['acao' => 'erro', 'mensagem' => implode(', ', $simNao)
                        . ': use sim ou não (ou 1 e 0).'];
                    continue;
                }

                if ($numero === '') {
                    $resultado[] = $item + ['acao' => 'erro', 'mensagem' => 'Falta o número do ramal.'];
                    continue;
                }
                if (isset($vistos[$numero])) {
                    $resultado[] = $item + ['acao' => 'erro',
                        'mensagem' => "O ramal {$numero} aparece de novo (já está na linha {$vistos[$numero]})."];
                    continue;
                }
                $vistos[$numero] = $nLinha;

                $id = $existentes[$numero] ?? null;
                if ($id !== null && !$atualizar) {
                    $resultado[] = $item + ['acao' => 'ignorado', 'mensagem' => 'Já existe; não foi alterado.'];
                    continue;
                }
                if ($id !== null && !$podeEditar) {
                    $resultado[] = $item + ['acao' => 'erro',
                        'mensagem' => 'Já existe, e o seu perfil não pode editar ramais.'];
                    continue;
                }

                $senhaGerada = null;
                if ($id === null && ($dados['senha_sip'] ?? '') === '') {
                    $senhaGerada = self::senhaForte();
                    $dados['senha_sip'] = $senhaGerada;
                }

                $pedido = $req->withParsedBody($dados);
                $resposta = $id === null
                    ? $this->ramais->criar($pedido, new SlimResponse())
                    : $this->ramais->atualizar($pedido, new SlimResponse(), ['id' => $id]);

                $retorno = json_decode((string) $resposta->getBody(), true) ?: [];
                if ($resposta->getStatusCode() >= 400) {
                    $campo = (string) ($retorno['campo'] ?? '');
                    $resultado[] = $item + ['acao' => 'erro',
                        'mensagem' => ($campo !== '' ? "{$campo}: " : '') . (string) ($retorno['erro'] ?? 'recusado')];
                    continue;
                }

                $resultado[] = $item + [
                    'acao' => $id === null ? 'criar' : 'atualizar',
                    'mensagem' => $id === null
                        ? ($senhaGerada !== null ? 'Senha SIP gerada.' : 'Senha SIP da planilha.')
                        : 'Campos preenchidos atualizados.',
                    // A senha só volta na importação de verdade: é quando
                    // ela passa a existir e alguém precisa configurar o aparelho.
                    'senha' => $simular ? null : $senhaGerada,
                ];
            }

            // Dentro da transação: conta com os ramais que esta planilha cria.
            $contratados = (int) Bd::valor('SELECT ramais_contratados FROM empresa WHERE id = 1');
            $ativos = (int) Bd::valor('SELECT COUNT(*) FROM ramais WHERE ativo = 1');
            $aviso = $contratados > 0 && $ativos > $contratados
                ? "Com esta planilha a central fica com {$ativos} ramais ativos, e o contrato é de {$contratados}."
                : null;

            if ($simular) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $conta = static fn (string $acao): int => count(array_filter($resultado, static fn ($r) => $r['acao'] === $acao));
        $resumo = [
            'criar' => $conta('criar'),
            'atualizar' => $conta('atualizar'),
            'ignorado' => $conta('ignorado'),
            'erro' => $conta('erro'),
        ];

        if (!$simular) {
            Auditoria::registrar($req->getAttribute('usuario'), 'importar', 'conn.ramais',
                                 'planilha de ramais', $resumo, $req->getServerParams()['REMOTE_ADDR'] ?? null);
        }

        return Resposta::json($res, ['simulado' => $simular, 'resumo' => $resumo, 'linhas' => $resultado,
                                     'aviso' => $aviso]);
    }

    // ------------------------------------------------------------------

    /**
     * Cabeçalho e linhas. Aceita ";" (o Excel em português), "," e tab, e
     * arquivo salvo em UTF-8 ou no Windows-1252 que o Excel ainda usa.
     *
     * @return array{0: string[], 1: list<array{0:int, 1:string[]}>, 2: ?string}
     */
    private static function ler(string $texto): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        $primeira = strtok($texto, "\r\n") ?: '';
        $contagem = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','),
                     "\t" => substr_count($primeira, "\t")];
        arsort($contagem);
        $sep = (string) array_key_first($contagem);

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $texto);
        rewind($fh);

        $cabecalho = null;
        $linhas = [];
        $n = 0;
        while (($celulas = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $n++;
            if ($celulas === [null] || implode('', array_map('trim', array_map('strval', $celulas))) === '') {
                continue;
            }
            if ($cabecalho === null) {
                $cabecalho = array_map(static fn ($c) => strtolower(trim((string) $c)), $celulas);
                continue;
            }
            $linhas[] = [$n, array_map(static fn ($c) => (string) $c, $celulas)];
        }
        fclose($fh);

        if ($cabecalho === null) {
            return [[], [], 'O arquivo não tem cabeçalho.'];
        }
        if (!in_array('numero', $cabecalho, true)) {
            return [[], [], 'A primeira linha precisa ter os nomes das colunas, com "numero" entre elas. '
                          . 'Baixe o modelo para ver o formato.'];
        }
        $desconhecidas = array_values(array_filter(
            $cabecalho,
            static fn (string $c): bool => $c !== '' && !in_array($c, self::COLUNAS, true)
        ));
        if ($desconhecidas !== []) {
            return [[], [], 'Colunas que o cadastro não conhece: ' . implode(', ', $desconhecidas)
                          . '. As aceitas são as do modelo.'];
        }
        if (count(array_unique($cabecalho)) !== count($cabecalho)) {
            return [[], [], 'Há uma coluna repetida no cabeçalho.'];
        }

        return [$cabecalho, $linhas, null];
    }

    /** O texto da célula no formato que o cadastro espera. */
    private static function valor(string $coluna, string $celula): string
    {
        $v = trim($celula);
        // O apóstrofo que a exportação põe contra fórmula no Excel.
        if (strlen($v) > 1 && $v[0] === "'" && str_contains('=+-@', $v[1])) {
            $v = substr($v, 1);
        }
        if ($v === '' || !in_array($coluna, self::SIM_NAO, true)) {
            return $v;
        }

        return match (mb_strtolower($v)) {
            '1', 'sim', 's', 'x', 'true', 'verdadeiro', 'yes', 'y' => '1',
            '0', 'não', 'nao', 'n', 'false', 'falso', 'no' => '0',
            default => $v,   // o cadastro recusa e a linha diz qual coluna
        };
    }

    private static function senhaForte(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $senha = '';
        for ($i = 0; $i < 16; $i++) {
            $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $senha;
    }

    /** ";" e BOM, que é como o Excel em português abre sem estragar acento. */
    private static function planilha(Response $res, string $nome, array $cabecalho, array $linhas): Response
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $cabecalho, ';', '"', '');
        foreach ($linhas as $l) {
            // Nome que comece com = + - @ vira fórmula no Excel de quem abrir.
            fputcsv($fh, array_map(
                static fn (string $v): string => $v !== '' && str_contains("=+-@\t\r", $v[0])
                    && preg_match('/^[+-]?[0-9]+$/', $v) !== 1 ? "'" . $v : $v,
                $l
            ), ';', '"', '');
        }
        rewind($fh);
        $conteudo = (string) stream_get_contents($fh);
        fclose($fh);

        $res->getBody()->write($conteudo);

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$nome}\"")
            ->withHeader('Cache-Control', 'no-store');
    }
}
