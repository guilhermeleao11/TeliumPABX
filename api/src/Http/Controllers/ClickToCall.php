<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\ClickToCall as C2c;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Click to call para outros sistemas, e a tela que cuida das chaves.
 *
 * A porta pública (/click2call) não tem sessão: quem chama é um CRM, um
 * ERP, uma página. Ela aceita GET e POST, e a chave no cabeçalho
 * X-Api-Key, no Authorization: Bearer ou no parâmetro token — há sistema
 * que só sabe montar uma URL.
 */
final class ClickToCall
{
    private const CHAVE_INVALIDA = 'Chave ausente, inválida ou desativada';

    /** Chaves erradas do mesmo IP, em dez minutos, antes de parar de tentar. */
    private const MAX_FALHAS = 20;

    // ------------------------------------------------------------------
    // A API
    // ------------------------------------------------------------------

    /** GET|POST /click2call?ramal=1001&numero=11999990000[&nome=Maria] */
    public function pedir(Request $req, Response $res): Response
    {
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $p = array_merge($req->getQueryParams(), (array) ($req->getParsedBody() ?? []));
        $ramal = preg_replace('/\D/', '', (string) ($p['ramal'] ?? '')) ?? '';
        $numero = C2c::numeroLimpo((string) ($p['numero'] ?? ''));

        if (self::chutando($ip)) {
            return self::falha($res, 429, 'Muitas chaves inválidas deste endereço. Tente de novo em alguns minutos.');
        }

        $chave = C2c::chaveDoToken(self::token($req, $p));
        if ($chave === null) {
            self::chaveRecusada('api', $ramal, $numero, $ip);

            return self::falha($res, 401, 'Chave de API ausente, inválida ou desativada.');
        }
        $id = (int) $chave['id'];
        Bd::executar('UPDATE click2call_chaves SET ultimo_uso = NOW() WHERE id = ?', [$id]);

        $recusa = match (true) {
            !C2c::ipPermitido($ip, $chave['ips']) => [403, "Esta chave não pode ser usada do endereço {$ip}."],
            $ramal === '' => [422, 'Informe o ramal (parâmetro "ramal").'],
            $numero === '' => [422, 'Informe o número a discar (parâmetro "numero").'],
            C2c::lista($chave['ramais']) !== [] && !in_array($ramal, C2c::lista($chave['ramais']), true)
                => [403, "Esta chave não pode fazer o ramal {$ramal} tocar."],
            default => null,
        };
        if ($recusa !== null) {
            self::registrar($id, 'api', $ramal, $numero, false, $recusa[1], null, $ip);

            return self::falha($res, $recusa[0], $recusa[1]);
        }

        return self::discar($res, $id, 'api', $ramal, $numero, (string) ($p['nome'] ?? ''),
                            (int) $chave['atender_auto'] === 1, $ip);
    }

    /**
     * Quem erra a chave sem parar está chutando: para por um tempo. Vale
     * para a API e para o app do Kommo, que entram pela mesma chave.
     */
    public static function chutando(string $ip): bool
    {
        $falhas = (int) Bd::valor(
            "SELECT COUNT(*) FROM click2call_pedidos
              WHERE ip = ? AND chave_id IS NULL AND resultado = ?
                AND criado_em >= NOW() - INTERVAL 10 MINUTE",
            // Pelo motivo, e não só pela chave vazia: os pedidos de uma chave
            // excluída também ficam sem chave (ON DELETE SET NULL), e um
            // sistema que usava muito a chave apagada era bloqueado à toa.
            [$ip, self::CHAVE_INVALIDA]
        );

        return $falhas >= self::MAX_FALHAS;
    }

    public static function chaveRecusada(string $origem, string $ramal, string $numero, string $ip): void
    {
        self::registrar(null, $origem, $ramal, $numero, false, self::CHAVE_INVALIDA, null, $ip);
    }

    /** A chave: X-Api-Key, Authorization: Bearer ou o parâmetro token. */
    public static function token(Request $req, array $p): string
    {
        $cabecalho = trim($req->getHeaderLine('X-Api-Key'));
        if ($cabecalho !== '') {
            return $cabecalho;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $req->getHeaderLine('Authorization'), $m) === 1) {
            return $m[1];
        }

        return (string) ($p['token'] ?? '');
    }

    /** Toca o ramal e disca, com o pedido registrado. Também a porta do app do Kommo. */
    public static function discar(
        Response $res, ?int $chaveId, string $origem, string $ramal, string $numero,
        string $nome, bool $auto, string $ip
    ): Response {
        $r = Bd::um('SELECT * FROM ramais WHERE numero = ? AND ativo = 1', [$ramal]);
        if ($r === null) {
            $msg = "O ramal {$ramal} não existe ou está desativado.";
            self::registrar($chaveId, $origem, $ramal, $numero, false, $msg, null, $ip);

            return self::falha($res, 404, $msg);
        }

        try {
            $c = C2c::originar($r, $numero, $nome, $auto);
        } catch (\DomainException $e) {
            self::registrar($chaveId, $origem, $ramal, $numero, false, $e->getMessage(), null, $ip);

            return self::falha($res, 409, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            self::registrar($chaveId, $origem, $ramal, $numero, false, $e->getMessage(), null, $ip);

            return self::falha($res, 422, $e->getMessage());
        } catch (\Throwable $e) {
            $msg = 'A central não respondeu: ' . $e->getMessage();
            self::registrar($chaveId, $origem, $ramal, $numero, false, $msg, null, $ip);

            return self::falha($res, 502, $msg);
        }

        $msg = "O ramal {$ramal} vai tocar; ao atender, a central disca {$c['destino']}.";
        self::registrar($chaveId, $origem, $ramal, $c['destino'], true, $msg, $c['uniqueid'], $ip);

        return Resposta::json($res, [
            'ok'       => true,
            'id'       => $c['uniqueid'],
            'ramal'    => $ramal,
            'numero'   => $c['destino'],
            'mensagem' => $msg,
        ]);
    }

    private static function falha(Response $res, int $status, string $mensagem): Response
    {
        return Resposta::json($res, ['ok' => false, 'erro' => $mensagem], $status);
    }

    public static function registrar(
        ?int $chaveId, string $origem, string $ramal, string $numero, bool $ok,
        string $resultado, ?string $uniqueid, string $ip
    ): void {
        Bd::executar(
            'INSERT INTO click2call_pedidos (chave_id, origem, ramal, numero, ok, resultado, uniqueid, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$chaveId, $origem, mb_substr($ramal, 0, 20) ?: null, mb_substr($numero, 0, 40) ?: null,
             (int) $ok, mb_substr($resultado, 0, 255), $uniqueid, $ip ?: null]
        );
    }

    // ------------------------------------------------------------------
    // A tela: Telium › Integrações / API
    // ------------------------------------------------------------------

    /** GET /click2call/chaves — as chaves (sem o texto delas) e os últimos pedidos. */
    public function listar(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'chaves' => array_map(static function (array $c): array {
                unset($c['token_hash']);
                $c['atender_auto'] = (int) $c['atender_auto'];
                $c['ativo'] = (int) $c['ativo'];

                return $c;
            }, Bd::todos('SELECT * FROM click2call_chaves ORDER BY nome')),
            'pedidos' => Bd::todos(
                'SELECT p.id, p.criado_em, p.origem, p.ramal, p.numero, p.ok, p.resultado, p.uniqueid, p.ip,
                        c.nome AS chave
                   FROM click2call_pedidos p LEFT JOIN click2call_chaves c ON c.id = p.chave_id
               ORDER BY p.id DESC LIMIT 50'
            ),
        ]);
    }

    /** POST /click2call/chaves — devolve o texto da chave, a única vez que ele existe. */
    public function criar(Request $req, Response $res): Response
    {
        [$dados, $erros] = self::lerChave((array) $req->getParsedBody());
        if ($erros !== []) {
            return Resposta::erro($res, 'Confira os campos destacados.', 422, ['campos' => $erros]);
        }

        $nova = C2c::novaChave();
        Bd::executar(
            'INSERT INTO click2call_chaves (nome, token_hash, token_fim, ramais, ips, atender_auto, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$dados['nome'], $nova['hash'], $nova['fim'], $dados['ramais'], $dados['ips'],
             $dados['atender_auto'], $dados['ativo']]
        );
        $id = (int) Bd::conexao()->lastInsertId();
        Auditoria::registrar($req->getAttribute('usuario'), 'criar', 'telium.integracoes', "chave click to call {$dados['nome']}");

        return Resposta::json($res, ['id' => $id, 'token' => $nova['token']], 201);
    }

    /** PUT /click2call/chaves/{id} */
    public function salvar(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        if (!Bd::valor('SELECT id FROM click2call_chaves WHERE id = ?', [$id])) {
            return Resposta::erro($res, 'Chave não encontrada.', 404);
        }
        [$dados, $erros] = self::lerChave((array) $req->getParsedBody());
        if ($erros !== []) {
            return Resposta::erro($res, 'Confira os campos destacados.', 422, ['campos' => $erros]);
        }

        Bd::executar(
            'UPDATE click2call_chaves SET nome = ?, ramais = ?, ips = ?, atender_auto = ?, ativo = ? WHERE id = ?',
            [$dados['nome'], $dados['ramais'], $dados['ips'], $dados['atender_auto'], $dados['ativo'], $id]
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', "chave click to call {$dados['nome']}");

        return Resposta::json($res, ['ok' => true]);
    }

    /** POST /click2call/chaves/{id}/renovar — a chave antiga para de valer na hora. */
    public function renovar(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT id, nome FROM click2call_chaves WHERE id = ?', [(int) $args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Chave não encontrada.', 404);
        }
        $nova = C2c::novaChave();
        Bd::executar('UPDATE click2call_chaves SET token_hash = ?, token_fim = ? WHERE id = ?',
                     [$nova['hash'], $nova['fim'], $c['id']]);
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', "nova chave click to call {$c['nome']}");

        return Resposta::json($res, ['id' => (int) $c['id'], 'token' => $nova['token']]);
    }

    /** DELETE /click2call/chaves/{id} */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $c = Bd::um('SELECT id, nome FROM click2call_chaves WHERE id = ?', [(int) $args['id']]);
        if ($c === null) {
            return Resposta::erro($res, 'Chave não encontrada.', 404);
        }
        Bd::executar('DELETE FROM click2call_chaves WHERE id = ?', [$c['id']]);
        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'telium.integracoes', "chave click to call {$c['nome']}");

        return Resposta::json($res, ['ok' => true]);
    }

    /** POST /click2call/testar {ramal, numero, nome, atender_auto} — a mesma chamada, pelo console. */
    public function testar(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        $ramal = preg_replace('/\D/', '', (string) ($c['ramal'] ?? '')) ?? '';
        $numero = C2c::numeroLimpo((string) ($c['numero'] ?? ''));
        if ($ramal === '' || $numero === '') {
            return self::falha($res, 422, 'Informe o ramal e o número.');
        }
        Auditoria::registrar($req->getAttribute('usuario'), 'testar', 'telium.integracoes', "click to call {$ramal} → {$numero}");

        return self::discar($res, null, 'console', $ramal, $numero, (string) ($c['nome'] ?? ''),
                            !empty($c['atender_auto']), (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''));
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,string>} dados e erros
     */
    private static function lerChave(array $c): array
    {
        $nome = trim((string) ($c['nome'] ?? ''));
        $ramais = C2c::lista((string) ($c['ramais'] ?? ''));
        $ips = C2c::lista((string) ($c['ips'] ?? ''));
        $erros = [];

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
            $erros['nome'] = 'Dê um nome de 2 a 80 caracteres — o do sistema que vai usar a chave.';
        }
        foreach ($ramais as $r) {
            if (preg_match('/^[0-9]{2,10}$/', $r) !== 1) {
                $erros['ramais'] = "\"{$r}\" não é um número de ramal.";
                break;
            }
        }
        foreach ($ips as $ip) {
            [$base, $bits] = array_pad(explode('/', $ip, 2), 2, null);
            if (filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ($bits !== null && (preg_match('/^\d{1,2}$/', $bits) !== 1 || (int) $bits > 32))) {
                $erros['ips'] = "\"{$ip}\" não é um IP nem uma faixa (como 200.1.2.3 ou 10.0.0.0/8).";
                break;
            }
        }

        return [[
            'nome'         => $nome,
            'ramais'       => $ramais === [] ? null : implode(', ', $ramais),
            'ips'          => $ips === [] ? null : implode(', ', $ips),
            'atender_auto' => (int) !empty($c['atender_auto']),
            'ativo'        => (int) (bool) ($c['ativo'] ?? 1),
        ], $erros];
    }
}
