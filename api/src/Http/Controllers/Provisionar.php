<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Provisionamento;
use Telium\Dominio\Rede;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * O endereço que o telefone busca ao ligar.
 *
 * Esta é a única porta da API sem sessão, e é assim porque tem de ser:
 * um telefone de mesa não faz login. O que a protege é o conjunto —
 * um segredo no caminho, o MAC precisar estar cadastrado, e a rede de
 * origem ser conferida. Cada pedido fica registrado, inclusive os
 * recusados: é o que responde "o telefone da sala 3 chegou a buscar a
 * configuração?" sem adivinhação.
 */
final class Provisionar
{
    /** GET /prov/{segredo}/{arquivo} */
    public function entregar(Request $req, Response $res, array $args): Response
    {
        $ip = self::origem($req);
        $agente = $req->getHeaderLine('User-Agent');
        $arquivo = (string) ($args['arquivo'] ?? '');

        // O MAC vem do nome do arquivo, que é o que o aparelho monta:
        // "cfg<mac>.xml" na Grandstream, "<mac>.cfg" nas outras.
        $mac = Provisionamento::macDoArquivo($arquivo);

        $segredo = Provisionamento::segredo();
        if ($segredo === '' || !hash_equals($segredo, (string) ($args['segredo'] ?? ''))) {
            Provisionamento::registrar($mac ?: null, $ip, $agente, 'segredo_errado');

            return self::naoEncontrado($res);
        }

        if (Provisionamento::soRedeLocal() && !Provisionamento::daRedeLocal($ip)) {
            Provisionamento::registrar($mac ?: null, $ip, $agente, 'rede_negada');

            return self::naoEncontrado($res);
        }

        if (!Provisionamento::macValido($mac)) {
            Provisionamento::registrar(null, $ip, $agente, 'mac_desconhecido');

            return self::naoEncontrado($res);
        }

        // Guardado com separador ou sem, maiúsculo ou minúsculo: compara
        // pelo que sobra de hexadecimal.
        $dispositivo = Bd::um(
            "SELECT * FROM dispositivos
              WHERE LOWER(REPLACE(REPLACE(REPLACE(mac, ':', ''), '-', ''), '.', '')) = ?",
            [$mac]
        );

        if ($dispositivo === null) {
            Provisionamento::registrar($mac, $ip, $agente, 'mac_desconhecido');

            return self::naoEncontrado($res);
        }

        $ramal = $dispositivo['ramal_id']
            ? Bd::um('SELECT * FROM ramais WHERE id = ? AND ativo = 1', [(int) $dispositivo['ramal_id']])
            : null;

        if ($ramal === null) {
            Provisionamento::registrar($mac, $ip, $agente, 'sem_ramal');
            // 404 e não 500: para o aparelho, é o mesmo "ainda não é meu
            // momento", e ele volta a perguntar no próximo boot.
            return self::naoEncontrado($res);
        }

        $conteudo = Provisionamento::gerar($dispositivo, $ramal);

        Bd::executar(
            "UPDATE dispositivos
                SET estado = 'provisionado', visto_em = NOW(), ip = ?, ultimo_agente = ?, vezes = vezes + 1
              WHERE id = ?",
            [substr($ip, 0, 45), substr($agente, 0, 160) ?: null, (int) $dispositivo['id']]
        );
        Provisionamento::registrar($mac, $ip, $agente, 'entregue');

        $corpo = $res->getBody();
        $corpo->write($conteudo);

        return $res->withBody($corpo)
            ->withHeader('Content-Type', str_ends_with($arquivo, '.xml')
                ? 'text/xml; charset=utf-8'
                : 'text/plain; charset=utf-8')
            ->withHeader('Content-Length', (string) strlen($conteudo))
            // A configuração carrega a senha SIP: não fica em cache de
            // proxy nenhum no caminho.
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /api/provisionamento — o que a tela precisa mostrar.
     *
     * O endereço é a parte que importa: sem ele, quem instala não tem
     * o que digitar no aparelho, e todo o resto do módulo não serve
     * para nada.
     */
    public function estado(Request $req, Response $res): Response
    {
        $segredo = Provisionamento::segredo();

        // O aparelho vai buscar pelo nome ou IP que a rede dele alcança,
        // e quem sabe disso é quem instala. O que a tela mostra é o
        // ponto de partida: o nome do console.
        $host = trim((string) Ambiente::get('SIP_DOMINIO', '')) ?: Rede::enderecoLocal();

        return Resposta::json($res, [
            'segredo'        => $segredo,
            'url'            => $segredo === '' ? '' : "https://{$host}/prov/{$segredo}",
            'so_rede_local'  => Provisionamento::soRedeLocal(),
            'redes_locais'   => Rede::redesLocais(),
            'fabricantes'    => Provisionamento::fabricantes(),
            'log'            => Bd::todos(
                'SELECT * FROM provisionamento_log ORDER BY id DESC LIMIT 30'
            ),
            'recusados'      => (int) Bd::valor(
                "SELECT COUNT(*) FROM provisionamento_log
                  WHERE resultado <> 'entregue' AND quando >= NOW() - INTERVAL 7 DAY"
            ),
        ]);
    }

    /** PUT /api/provisionamento — trocar o segredo ou a trava de rede. */
    public function salvar(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        // Arrow function devolve o que a expressão devolver, e Bd::executar
        // devolve algo: declarada como void, o PHP recusa o arquivo inteiro.
        $gravar = static function (string $chave, string $valor): void {
            Bd::executar(
                'INSERT INTO sistema (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$chave, $valor]
            );
        };

        if (!empty($c['novo_segredo'])) {
            // Trocar o segredo derruba todos os aparelhos até alguém
            // reconfigurar a URL neles. A tela avisa antes de chamar.
            $gravar('prov_segredo', bin2hex(random_bytes(16)));
            Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'conn.provisionamento',
                                 'segredo trocado');
        }

        if (array_key_exists('so_rede_local', $c)) {
            $gravar('prov_so_rede_local', empty($c['so_rede_local']) ? '0' : '1');
        }

        return $this->estado($req, $res);
    }

    /**
     * GET /api/provisionamento/{id}/previa — o arquivo, sem o aparelho.
     *
     * Quem instala precisa ver o que vai ser entregue antes de o
     * telefone buscar — e depois, para conferir por que ele não
     * registrou. A senha SIP aparece aqui porque é o conteúdo real do
     * arquivo; a rota exige o módulo e a permissão de editar.
     */
    public function previa(Request $req, Response $res, array $args): Response
    {
        $d = Bd::um('SELECT * FROM dispositivos WHERE id = ?', [(int) $args['id']]);
        if ($d === null) {
            return Resposta::erro($res, 'Aparelho não encontrado.', 404);
        }

        $ramal = $d['ramal_id']
            ? Bd::um('SELECT * FROM ramais WHERE id = ? AND ativo = 1', [(int) $d['ramal_id']])
            : null;

        if ($ramal === null) {
            return Resposta::erro(
                $res,
                'Este aparelho não tem ramal vinculado (ou o ramal está inativo), '
                . 'então não há configuração a entregar.',
                422
            );
        }

        return Resposta::json($res, [
            'arquivo'   => Provisionamento::nomeDoArquivo((string) $d['fabricante'], (string) $d['mac']),
            'conteudo'  => Provisionamento::gerar($d, $ramal),
            'ramal'     => $ramal['numero'],
        ]);
    }

    /**
     * Sempre 404, nunca "MAC não cadastrado".
     *
     * Dizer qual das conferências falhou entrega a quem estiver varrendo
     * exatamente o que ele precisa para acertar na próxima. O motivo real
     * fica no registro, que é onde quem administra vai olhar.
     */
    private static function naoEncontrado(Response $res): Response
    {
        return $res->withStatus(404)->withHeader('Content-Type', 'text/plain');
    }

    /** O endereço de quem pediu, respeitando o proxy da própria casa. */
    private static function origem(Request $req): string
    {
        $real = trim($req->getHeaderLine('X-Real-IP'));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }

        return (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
