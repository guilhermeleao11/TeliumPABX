<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\ClickToCall as C2c;
use Telium\Dominio\Kommo as K;
use Telium\Http\Controllers\ClickToCall as CtrlC2c;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Integração com o Kommo: as portas do app instalado no CRM e a tela
 * Telium › Integrações / API › Kommo.
 *
 * Duas portas não têm sessão do console:
 *   POST /kommo/discar        o app pede a ligação. Quem protege é a chave
 *                             do app (uma chave de click to call); o ramal
 *                             sai do usuário do Kommo que clicou, pelo
 *                             cadastro desta central — o app não escolhe.
 *   GET  /kommo/gravacao/...  o player do Kommo toca a gravação. O link é
 *                             assinado: sem a assinatura, 404.
 */
final class Kommo
{
    /** Os navegadores de onde o app pode chamar: as telas do Kommo. */
    private const ORIGENS = '#^https://[a-z0-9-]+\.(kommo\.com|amocrm\.com|amocrm\.ru)$#';

    private const TIPOS = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'gsm' => 'audio/x-gsm'];

    private const APP_DIR = __DIR__ . '/../../../recursos/kommo-app';

    // ------------------------------------------------------------------
    // O app no Kommo
    // ------------------------------------------------------------------

    /** OPTIONS /kommo/discar — o navegador pergunta antes de mandar o X-Api-Key. */
    public function preparar(Request $req, Response $res): Response
    {
        return self::cors($req, $res)->withStatus(204);
    }

    /** POST /kommo/discar {usuario_id, telefone, nome} — o clique no telefone do contato. */
    public function discar(Request $req, Response $res): Response
    {
        $res = self::cors($req, $res);
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
        $p = array_merge($req->getQueryParams(), (array) ($req->getParsedBody() ?? []));
        $cfg = K::config();
        $usuario = (int) ($p['usuario_id'] ?? 0);
        $numero = self::paraDiscar((string) ($p['telefone'] ?? ''), $cfg);

        if (CtrlC2c::chutando($ip)) {
            return self::falha($res, 429, 'Muitas chaves inválidas deste endereço. Tente de novo em alguns minutos.');
        }

        $chave = C2c::chaveDoToken(CtrlC2c::token($req, $p));
        if ($chave === null || (int) $chave['id'] !== (int) ($cfg['chave_id'] ?? 0)) {
            CtrlC2c::chaveRecusada('kommo', '', $numero, $ip);

            return self::falha($res, 401, 'Chave do app inválida. Confira a "Chave da central" nas configurações do app no Kommo.');
        }
        $id = (int) $chave['id'];
        Bd::executar('UPDATE click2call_chaves SET ultimo_uso = NOW() WHERE id = ?', [$id]);

        if (!(int) ($cfg['ativo'] ?? 0)) {
            return self::falha($res, 403, 'A integração com o Kommo está desligada na central.');
        }

        $u = $usuario > 0 ? Bd::um('SELECT nome, ramal FROM kommo_usuarios WHERE kommo_id = ? AND ativo = 1', [$usuario]) : null;
        $ramal = (string) ($u['ramal'] ?? '');

        $recusa = match (true) {
            !C2c::ipPermitido($ip, $chave['ips']) => [403, "Esta chave não pode ser usada do endereço {$ip}."],
            $u === null => [422, 'Seu usuário do Kommo não está na central. Peça ao administrador para sincronizar os usuários em Integrações › Kommo.'],
            $ramal === '' => [422, "{$u['nome']} ainda não tem ramal na central. Peça ao administrador para escolher o ramal em Integrações › Kommo."],
            $numero === '' => [422, 'O contato não tem um número de telefone válido.'],
            C2c::lista($chave['ramais']) !== [] && !in_array($ramal, C2c::lista($chave['ramais']), true)
                => [403, "A chave do app não pode fazer o ramal {$ramal} tocar."],
            default => null,
        };
        if ($recusa !== null) {
            CtrlC2c::registrar($id, 'kommo', $ramal, $numero, false, $recusa[1], null, $ip);

            return self::falha($res, $recusa[0], $recusa[1]);
        }

        return CtrlC2c::discar($res, $id, 'kommo', $ramal, $numero, (string) ($p['nome'] ?? ''),
                               (int) $chave['atender_auto'] === 1, $ip);
    }

    /**
     * O telefone do Kommo (+55 11 99999-0000) do jeito que o ramal discaria.
     * Os números do Brasil perdem o +55; o do DDD da central perde também o
     * DDD, se a tela pedir; e o prefixo de saída, se houver, vai na frente.
     */
    public static function paraDiscar(string $telefone, array $cfg): string
    {
        $t = trim($telefone);
        $d = preg_replace('/\D/', '', $t) ?? '';
        if ($d === '') {
            return '';
        }
        $internacional = str_starts_with($t, '+') && !str_starts_with($d, '55');
        if (!$internacional && str_starts_with($d, '55') && (strlen($d) === 12 || strlen($d) === 13)) {
            $d = substr($d, 2);
        }
        if ($internacional) {
            $d = '00' . $d;
        } else {
            $d = ltrim($d, '0') ?: $d;
            $ddd = (string) ($cfg['ddd'] ?? '');
            if ((int) ($cfg['local_sem_ddd'] ?? 0) === 1 && $ddd !== '' && str_starts_with($d, $ddd)
                && (strlen($d) === 10 || strlen($d) === 11)) {
                $d = substr($d, 2);
            }
        }

        return preg_replace('/\D/', '', (string) ($cfg['prefixo'] ?? '')) . $d;
    }

    /** GET /kommo/gravacao/{id}/{assinatura}.{ext} — o player do Kommo. */
    public function gravacao(Request $req, Response $res, array $args): Response
    {
        $arquivo = (string) $args['arquivo'];
        $assinatura = pathinfo($arquivo, PATHINFO_FILENAME);
        $caminho = K::arquivoDaGravacao((string) $args['id'], $assinatura);
        if ($caminho === null) {
            return Resposta::erro($res, 'Gravação não encontrada.', 404);
        }
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

        return Resposta::arquivo($req, $res, $caminho, self::TIPOS[$ext] ?? 'application/octet-stream');
    }

    /** Só as telas do Kommo podem chamar a central pelo navegador. */
    private static function cors(Request $req, Response $res): Response
    {
        $origem = $req->getHeaderLine('Origin');
        if ($origem === '' || preg_match(self::ORIGENS, $origem) !== 1) {
            return $res;
        }

        return $res->withHeader('Access-Control-Allow-Origin', $origem)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-Api-Key')
            ->withHeader('Access-Control-Max-Age', '600');
    }

    private static function falha(Response $res, int $status, string $mensagem): Response
    {
        return Resposta::json($res, ['ok' => false, 'erro' => $mensagem], $status);
    }

    // ------------------------------------------------------------------
    // A tela
    // ------------------------------------------------------------------

    /** GET /kommo */
    public function ver(Request $req, Response $res): Response
    {
        $cfg = K::config();
        $chave = $cfg['chave_id'] ? Bd::um('SELECT id, token_fim, ativo, ultimo_uso FROM click2call_chaves WHERE id = ?',
                                            [$cfg['chave_id']]) : null;
        $token = (string) ($cfg['token'] ?? '');

        return Resposta::json($res, [
            'config' => [
                'ativo'             => (int) $cfg['ativo'],
                'subdominio'        => $cfg['subdominio'],
                'token_definido'    => $token !== '',
                'token_fim'         => $token !== '' ? substr($token, -4) : null,
                'url_publica'       => $cfg['url_publica'],
                'url_publica_padrao' => K::urlPublica(['url_publica' => '']),
                'ddd'               => $cfg['ddd'],
                'prefixo'           => $cfg['prefixo'],
                'local_sem_ddd'     => (int) $cfg['local_sem_ddd'],
                'registrar_entrada' => (int) $cfg['registrar_entrada'],
                'registrar_saida'   => (int) $cfg['registrar_saida'],
                'criar_lead'        => (int) $cfg['criar_lead'],
                'desde'             => $cfg['desde'],
                'ultimo_envio'      => $cfg['ultimo_envio'],
                'ultimo_estado'     => $cfg['ultimo_estado'],
            ],
            'chave'    => $chave,
            'usuarios' => Bd::todos('SELECT kommo_id, nome, email, ramal, ativo FROM kommo_usuarios ORDER BY ativo DESC, nome'),
            'ramais'   => Bd::todos('SELECT numero, nome FROM ramais WHERE ativo = 1 ORDER BY CAST(numero AS UNSIGNED), numero'),
            'chamadas' => Bd::todos(
                'SELECT k.*, u.nome AS usuario FROM kommo_chamadas k
                   LEFT JOIN kommo_usuarios u ON u.kommo_id = k.kommo_usuario
               ORDER BY k.inicio DESC LIMIT 50'
            ),
        ]);
    }

    /** PUT /kommo */
    public function salvar(Request $req, Response $res): Response
    {
        $c = (array) $req->getParsedBody();
        $antes = K::config();
        $erros = [];

        $sub = trim((string) ($c['subdominio'] ?? ''));
        if ($sub !== '' && K::host($sub) === null) {
            $erros['subdominio'] = 'Use o endereço da conta: "empresa" ou "empresa.kommo.com".';
        }
        $url = rtrim(trim((string) ($c['url_publica'] ?? '')), '/');
        if ($url !== '' && preg_match('#^https://[^\s/]+(/\S*)?$#', $url) !== 1) {
            $erros['url_publica'] = 'Comece com https:// — o Kommo só toca gravação de endereço seguro.';
        }
        $ddd = trim((string) ($c['ddd'] ?? ''));
        if ($ddd !== '' && preg_match('/^[1-9][0-9]$/', $ddd) !== 1) {
            $erros['ddd'] = 'Dois dígitos, como 11.';
        }
        $prefixo = trim((string) ($c['prefixo'] ?? ''));
        if ($prefixo !== '' && preg_match('/^[0-9]{1,4}$/', $prefixo) !== 1) {
            $erros['prefixo'] = 'Só dígitos, até 4 (o 0 da linha externa, por exemplo).';
        }
        $token = trim((string) ($c['token'] ?? ''));
        $ativo = (int) !empty($c['ativo']);
        $tokenFinal = $token !== '' ? $token : (string) ($antes['token'] ?? '');
        if ($ativo && ($sub === '' || $tokenFinal === '')) {
            $erros[$sub === '' ? 'subdominio' : 'token'] = 'Para ligar a integração, informe a conta e o token do Kommo.';
        }
        if ($erros !== []) {
            return Resposta::erro($res, 'Confira os campos destacados.', 422, ['campos' => $erros]);
        }

        Bd::executar(
            'UPDATE kommo_config SET ativo = ?, subdominio = ?, token = ?, url_publica = ?, ddd = ?, prefixo = ?,
                    local_sem_ddd = ?, registrar_entrada = ?, registrar_saida = ?, criar_lead = ?,
                    gravacao_segredo = COALESCE(gravacao_segredo, ?),
                    desde = IF(? = 1 AND (desde IS NULL OR ativo = 0), NOW(), desde)
              WHERE id = 1',
            [$ativo, $sub ?: null, $tokenFinal ?: null, $url ?: null, $ddd ?: null, $prefixo ?: null,
             (int) !empty($c['local_sem_ddd']), (int) !empty($c['registrar_entrada']),
             (int) !empty($c['registrar_saida']), (int) !empty($c['criar_lead']),
             bin2hex(random_bytes(32)), $ativo]
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', 'Kommo',
                             ['ativo' => $ativo, 'conta' => $sub, 'token_trocado' => $token !== '']);

        return Resposta::json($res, ['ok' => true]);
    }

    /** POST /kommo/testar */
    public function testar(Request $req, Response $res): Response
    {
        return Resposta::json($res, K::testar(K::config()));
    }

    /** POST /kommo/usuarios/sincronizar */
    public function sincronizar(Request $req, Response $res): Response
    {
        $r = K::sincronizarUsuarios(K::config());
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', 'Kommo: usuários', ['resultado' => $r['detalhe']]);

        return Resposta::json($res, $r, $r['ok'] ? 200 : 502);
    }

    /** PUT /kommo/usuarios {ramais: {kommo_id: "1001" | ""}} */
    public function salvarUsuarios(Request $req, Response $res): Response
    {
        $mapa = (array) (((array) $req->getParsedBody())['ramais'] ?? []);
        $existentes = array_fill_keys(array_map('strval', array_column(Bd::todos('SELECT numero FROM ramais'), 'numero')), true);
        foreach ($mapa as $kommoId => $ramal) {
            $ramal = trim((string) $ramal);
            if ($ramal !== '' && !isset($existentes[$ramal])) {
                return Resposta::erro($res, "O ramal {$ramal} não existe.", 422);
            }
        }
        foreach ($mapa as $kommoId => $ramal) {
            Bd::executar('UPDATE kommo_usuarios SET ramal = ? WHERE kommo_id = ?',
                         [trim((string) $ramal) ?: null, (int) $kommoId]);
        }
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', 'Kommo: ramais dos usuários');

        return Resposta::json($res, ['ok' => true]);
    }

    /**
     * POST /kommo/chave — a chave que vai nas configurações do app. Gera
     * outra a cada clique: a anterior para de valer na hora.
     */
    public function chave(Request $req, Response $res): Response
    {
        $cfg = K::config();
        $nova = C2c::novaChave();
        $id = (int) ($cfg['chave_id'] ?? 0);
        if ($id > 0 && Bd::valor('SELECT id FROM click2call_chaves WHERE id = ?', [$id])) {
            Bd::executar('UPDATE click2call_chaves SET token_hash = ?, token_fim = ?, ativo = 1 WHERE id = ?',
                         [$nova['hash'], $nova['fim'], $id]);
        } else {
            Bd::executar("INSERT INTO click2call_chaves (nome, token_hash, token_fim, ativo) VALUES ('Kommo (app do CRM)', ?, ?, 1)",
                         [$nova['hash'], $nova['fim']]);
            $id = (int) Bd::conexao()->lastInsertId();
            Bd::executar('UPDATE kommo_config SET chave_id = ? WHERE id = 1', [$id]);
        }
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'telium.integracoes', 'Kommo: chave do app');

        return Resposta::json($res, ['token' => $nova['token']]);
    }

    /** POST /kommo/enviar — manda agora o que está pendente, sem esperar o temporizador. */
    public function enviar(Request $req, Response $res): Response
    {
        return Resposta::json($res, K::enviarPendentes(20));
    }

    /** GET /kommo/app.zip — o arquivo que se envia ao criar a integração no Kommo. */
    public function app(Request $req, Response $res): Response
    {
        if (!class_exists(\ZipArchive::class)) {
            return Resposta::erro($res, 'A extensão zip do PHP não está ativa no servidor.', 500);
        }
        $base = realpath(self::APP_DIR);
        if ($base === false) {
            return Resposta::erro($res, 'Os arquivos do app não estão na central.', 500);
        }

        $zipNome = tempnam(sys_get_temp_dir(), 'telium-kommo-');
        $zip = new \ZipArchive();
        if ($zip->open($zipNome, \ZipArchive::OVERWRITE) !== true) {
            return Resposta::erro($res, 'Não foi possível montar o arquivo no servidor.', 500);
        }
        // Os arquivos na raiz do zip, sem pasta: é o que o Kommo espera.
        $itens = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($itens as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), ltrim(substr($f->getPathname(), strlen($base)), '/'));
            }
        }
        $zip->close();

        $tamanho = (int) filesize($zipNome);
        $fluxo = fopen($zipNome, 'rb');
        @unlink($zipNome);
        if ($fluxo === false) {
            return Resposta::erro($res, 'Não foi possível ler o arquivo no servidor.', 500);
        }

        return $res->withBody(new \Slim\Psr7\Stream($fluxo))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) $tamanho)
            ->withHeader('Content-Disposition', 'attachment; filename="telium-pabx-kommo.zip"');
    }
}
