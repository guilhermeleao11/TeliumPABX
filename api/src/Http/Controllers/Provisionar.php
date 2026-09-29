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
     * POST /api/provisionamento/linphone — o QR Code de um ramal.
     *
     * Devolve só a URL com o token; a senha nunca volta para a tela. Em
     * branco, vale a senha do cadastro — que é a que o Asterisk confere.
     */
    public function gerarLinphone(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        $ramalId = (int) ($c['ramal_id'] ?? 0);
        $senha = (string) ($c['senha'] ?? '');

        $ramal = $ramalId > 0
            ? Bd::um('SELECT * FROM ramais WHERE id = ? AND ativo = 1', [$ramalId])
            : null;
        if ($ramal === null) {
            return Resposta::erro($res, 'Escolha um ramal ativo.', 422);
        }
        if ((int) $ramal['webrtc'] === 1) {
            return Resposta::erro(
                $res,
                "O ramal {$ramal['numero']} é do softphone do navegador (WebRTC) e só fala por "
                . 'WebSocket. O Linphone registra por UDP: use um ramal SIP comum.',
                422
            );
        }
        if ($senha !== '' && (strlen($senha) > 120 || preg_match('/[\x00-\x1f\x7f]/', $senha) === 1)) {
            return Resposta::erro($res, 'A senha SIP tem de ter até 120 caracteres, sem quebra de linha.', 422);
        }

        // O endereço é o que o administrador digitou no navegador para
        // abrir o console: numa central instalada sem nome próprio, é o
        // único que sabidamente chega a ela.
        $uri = $req->getUri();
        $host = strtolower($uri->getHost());
        if (!Provisionamento::enderecoValido($host)) {
            return Resposta::erro($res, 'Não foi possível saber o endereço desta central. '
                . 'Abra o console pelo IP ou pelo nome do servidor e gere o QR de novo.', 422);
        }

        // Console aberto por http, sem nginx na frente (a bancada): o QR
        // segue o mesmo caminho, porta incluída. Pelo nginx, é https se o
        // certificado passa na conferência do Linphone, senão http na 80.
        if ($uri->getScheme() === 'http') {
            $esquema = 'http';
            $porta = $uri->getPort();
        } else {
            $esquema = Provisionamento::httpsConfiavel($host) ? 'https' : 'http';
            $porta = $esquema === 'https' ? $uri->getPort() : null;
        }
        $base = "{$esquema}://{$host}" . ($porta !== null && !in_array($porta, [80, 443], true) ? ":{$porta}" : '');

        Provisionamento::limparConvites();

        $token = Provisionamento::novoToken();
        $usuario = $req->getAttribute('usuario');

        // O prazo é contado pelo relógio do banco, que é o mesmo que
        // confere a validade na entrega.
        Bd::executar(
            'INSERT INTO provisionamento_convite
                    (token_hash, ramal_id, senha_cifrada, servidor, esquema, criado_por, expira_em)
             VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)',
            [
                Provisionamento::hashDoToken($token),
                (int) $ramal['id'],
                $senha === '' ? null : Provisionamento::cifrarSenha($senha, $token),
                $host,
                $esquema,
                $usuario['id'] ?? null,
                Provisionamento::LINPHONE_VALIDADE_SEG,
            ]
        );
        $expira = (string) Bd::valor(
            'SELECT expira_em FROM provisionamento_convite WHERE token_hash = ?',
            [Provisionamento::hashDoToken($token)]
        );

        Auditoria::registrar($usuario, 'criar', 'conn.provisionamento',
                             "QR do Linphone para o ramal {$ramal['numero']}");

        $destino = Provisionamento::destinoLinphone($host);

        return Resposta::json($res, [
            'url'          => "{$base}/p/{$token}",
            'esquema'      => $esquema,
            // O celular no 4G não alcança IP de rede privada: só pelo
            // Wi-Fi da mesma rede. A tela avisa.
            'privado'      => Provisionamento::enderecoPrivado($host),
            'expira_em'    => $expira,
            'validade_seg' => Provisionamento::LINPHONE_VALIDADE_SEG,
            'ramal'        => (string) $ramal['numero'],
            'nome'         => (string) $ramal['nome'],
            'servidor'     => $destino['servidor'],
            'porta'        => $destino['porta'],
            'transporte'   => strtoupper($destino['transporte']),
            // Digitada e diferente da do cadastro, o Asterisk vai recusar o
            // registro. A tela avisa; quem decide é quem está instalando.
            'senha_difere' => $senha !== '' && !hash_equals((string) $ramal['senha_sip'], $senha),
        ], 201);
    }

    /**
     * GET /p/{token} — o que o Linphone busca ao ler o QR.
     *
     * Sem sessão, como o /prov/: o aplicativo não faz login. O que
     * protege é o token — 192 bits, dez minutos, uma vez só. A trava de
     * rede interna não vale aqui: o celular costuma estar no 4G.
     *
     * Responde também pela porta 80, sem redirecionar para https: é o
     * caminho da central sem certificado de confiança. Por ali o XML
     * viaja sem criptografia — o preço de não ter certificado, e a tela
     * diz isso a quem gera o QR.
     *
     * Token usado ou vencido responde 410, nunca 200 com página de
     * erro: a liblinphone apaga as credenciais que tinha antes de ler
     * o corpo, e um 200 sem conta deixaria o aplicativo sem nenhuma.
     */
    public function linphone(Request $req, Response $res, array $args): Response
    {
        $ip = self::origem($req);
        $agente = $req->getHeaderLine('User-Agent');
        $token = strtolower((string) ($args['token'] ?? ''));

        if (!Provisionamento::tokenValido($token)) {
            Provisionamento::registrar(null, $ip, $agente, 'token_desconhecido');

            return self::naoEncontrado($res);
        }

        $convite = Bd::um(
            'SELECT c.*, (c.expira_em <= NOW()) AS vencido, r.numero
               FROM provisionamento_convite c
               LEFT JOIN ramais r ON r.id = c.ramal_id
              WHERE c.token_hash = ?',
            [Provisionamento::hashDoToken($token)]
        );

        if ($convite === null) {
            Provisionamento::registrar(null, $ip, $agente, 'token_desconhecido');

            return self::naoEncontrado($res);
        }

        $numero = $convite['numero'] !== null ? (string) $convite['numero'] : null;

        if ($convite['usado_em'] !== null) {
            Provisionamento::registrar(null, $ip, $agente, 'token_usado', $numero);

            return self::expirado($res);
        }
        if ((int) $convite['vencido'] === 1) {
            Provisionamento::registrar(null, $ip, $agente, 'token_expirado', $numero);

            return self::expirado($res);
        }

        // Convite feito para https não sai por http: o esquema foi
        // escolhido na geração, e o QR só aponta para ele.
        if ($convite['esquema'] === 'https' && $req->getUri()->getScheme() !== 'https') {
            Provisionamento::registrar(null, $ip, $agente, 'token_desconhecido', $numero);

            return self::naoEncontrado($res);
        }

        // Queima o token antes de entregar, e numa condição só: dois
        // pedidos ao mesmo tempo não levam a senha duas vezes.
        $queimou = Bd::executar(
            'UPDATE provisionamento_convite
                SET usado_em = NOW(), usado_ip = ?, senha_cifrada = NULL
              WHERE id = ? AND usado_em IS NULL AND expira_em > NOW()',
            [substr($ip, 0, 45), (int) $convite['id']]
        );
        if ($queimou !== 1) {
            Provisionamento::registrar(null, $ip, $agente, 'token_usado', $numero);

            return self::expirado($res);
        }

        $ramal = Bd::um('SELECT * FROM ramais WHERE id = ? AND ativo = 1', [(int) $convite['ramal_id']]);
        if ($ramal === null) {
            Provisionamento::registrar(null, $ip, $agente, 'sem_ramal', $numero);

            return self::naoEncontrado($res);
        }

        $senha = (string) $ramal['senha_sip'];
        if ($convite['senha_cifrada'] !== null) {
            $digitada = Provisionamento::decifrarSenha((string) $convite['senha_cifrada'], $token);
            if ($digitada === null) {
                Provisionamento::registrar(null, $ip, $agente, 'token_desconhecido', $numero);

                return self::naoEncontrado($res);
            }
            $senha = $digitada;
        }

        $conteudo = Provisionamento::linphone($ramal, $senha,
            Provisionamento::destinoLinphone($convite['servidor'] !== null ? (string) $convite['servidor'] : null));
        Provisionamento::registrar(null, $ip, $agente, 'entregue', $numero);

        $corpo = $res->getBody();
        $corpo->write($conteudo);

        return $res->withBody($corpo)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Length', (string) strlen($conteudo))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private static function expirado(Response $res): Response
    {
        return $res->withStatus(410)->withHeader('Content-Type', 'text/plain')
                   ->withHeader('Cache-Control', 'no-store');
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
        // Só o REMOTE_ADDR. O X-Real-IP que ficava aqui vinha do próprio
        // cliente — o nginx passa ao PHP-FPM direto, e não o reescreve —,
        // então bastava mandar "X-Real-IP: 127.0.0.1" da internet para a
        // trava de rede interna deixar passar e entregar a senha SIP.
        return (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
