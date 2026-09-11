<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Senha;
use Telium\Dominio\Sessao;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

final class Autenticacao
{
    private const MAX_TENTATIVAS = 5;
    private const BLOQUEIO_MINUTOS = 15;

    /** POST /api/auth/login */
    public function login(Request $req, Response $res): Response
    {
        $corpo   = (array) $req->getParsedBody();
        $login   = trim((string) ($corpo['usuario'] ?? ''));
        $senha   = (string) ($corpo['senha'] ?? '');
        $ip      = $this->ip($req);

        if ($login === '' || $senha === '') {
            return Resposta::erro($res, 'Informe usuário e senha', 422);
        }

        $usuario = Bd::um(
            'SELECT u.*, p.chave AS perfil_chave, p.nome AS perfil_nome, p.cor AS perfil_cor
               FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
              WHERE u.usuario = ?',
            [$login]
        );

        // Mensagem genérica de propósito: não revela se o usuário existe.
        $generico = 'Usuário ou senha inválidos';

        if ($usuario === null) {
            Auditoria::registrar(null, 'login_falha', 'auth', $login, ['motivo' => 'inexistente'], $ip);
            return Resposta::erro($res, $generico, 401);
        }

        if ($usuario['bloqueado_ate'] !== null && strtotime($usuario['bloqueado_ate']) > time()) {
            return Resposta::erro($res, 'Conta temporariamente bloqueada por excesso de tentativas', 429);
        }

        if (!Senha::verificar($senha, (string) $usuario['senha_hash'])) {
            $tentativas = (int) $usuario['tentativas_login'] + 1;
            $bloqueia = $tentativas >= self::MAX_TENTATIVAS;

            Bd::executar(
                'UPDATE usuarios SET tentativas_login = ?,
                        bloqueado_ate = ' . ($bloqueia ? 'DATE_ADD(NOW(), INTERVAL ? MINUTE)' : 'NULL') . '
                  WHERE id = ?',
                $bloqueia
                    ? [$tentativas, self::BLOQUEIO_MINUTOS, $usuario['id']]
                    : [$tentativas, $usuario['id']]
            );

            Auditoria::registrar($usuario, 'login_falha', 'auth', $login, ['tentativa' => $tentativas], $ip);
            return Resposta::erro($res, $generico, 401);
        }

        if ($usuario['status'] !== 'ativo') {
            return Resposta::erro($res, "Usuário {$usuario['status']}. Procure o administrador.", 403);
        }

        // 2FA fica para a etapa seguinte; o campo já existe no banco.
        if ((int) $usuario['totp_ativo'] === 1) {
            $codigo = (string) ($corpo['codigo'] ?? '');
            if ($codigo === '') {
                return Resposta::json($res, ['precisa_2fa' => true], 200);
            }
        }

        Bd::executar(
            'UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_acesso = NOW() WHERE id = ?',
            [$usuario['id']]
        );

        // Regrava o hash se os parâmetros ficaram para trás
        if (Senha::precisaAtualizar((string) $usuario['senha_hash'])) {
            Bd::executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [Senha::criar($senha), $usuario['id']]);
        }

        $token = Sessao::criar((int) $usuario['id'], $ip, $req->getHeaderLine('User-Agent'));
        Auditoria::registrar($usuario, 'login', 'auth', $login, [], $ip);

        $res = $res->withHeader(
            'Set-Cookie',
            sprintf(
                'telium_sessao=%s; Path=/; HttpOnly; SameSite=Strict; Secure; Max-Age=%d',
                $token,
                Ambiente::int('SESSAO_MINUTOS', 30) * 60
            )
        );

        return Resposta::json($res, [
            'token'   => $token,
            'usuario' => $this->publico($usuario),
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(Request $req, Response $res): Response
    {
        $token = (string) $req->getAttribute('token');
        Sessao::encerrar($token);
        Auditoria::registrar($req->getAttribute('usuario'), 'logout', 'auth', null, [], $this->ip($req));

        return Resposta::json(
            $res->withHeader('Set-Cookie', 'telium_sessao=; Path=/; HttpOnly; Max-Age=0'),
            ['ok' => true]
        );
    }

    /** GET /api/me — usuário, permissões e menu já resolvidos. */
    public function eu(Request $req, Response $res): Response
    {
        $usuario = (array) $req->getAttribute('usuario');
        $allow   = (array) $req->getAttribute('allow');
        $caps    = (array) $req->getAttribute('caps');

        return Resposta::json($res, [
            'usuario'    => $this->publico($usuario),
            'permissoes' => ['allow' => $allow, 'caps' => $caps],
            'empresa'    => Bd::um('SELECT nome, plano, ramais_contratados FROM empresa WHERE id = 1'),
            'softphone'  => $this->softphone($usuario),
        ]);
    }

    /**
     * O que o softphone do navegador precisa para registrar.
     *
     * A senha SIP vai para o navegador porque é assim que um softphone
     * WebRTC autentica — não há como registrar sem ela. O que limita o
     * estrago é o alcance: só o ramal do próprio usuário, só quando ele
     * está marcado como WebRTC, e só dentro da sessão já autenticada.
     *
     * @return array<string,mixed>
     */
    private function softphone(array $usuario): array
    {
        $fora = ['disponivel' => false, 'motivo' => 'Este usuário não tem ramal WebRTC.'];

        if (($usuario['ramal'] ?? '') === '') {
            return $fora;
        }

        $r = Bd::um(
            'SELECT numero, nome, senha_sip, webrtc, ativo FROM ramais WHERE numero = ?',
            [$usuario['ramal']]
        );

        if ($r === null || (int) $r['ativo'] !== 1) {
            return ['disponivel' => false, 'motivo' => 'O ramal deste usuário não existe ou está inativo.'];
        }
        if ((int) $r['webrtc'] !== 1) {
            return ['disponivel' => false,
                    'motivo' => "O ramal {$r['numero']} não está marcado como WebRTC. "
                              . 'Ligue a opção no cadastro do ramal para usar o softphone do navegador.'];
        }

        $ws = (string) Ambiente::get('SOFTPHONE_WS', '');
        $dominio = (string) Ambiente::get('SIP_DOMINIO', '');

        if ($ws === '' || $dominio === '') {
            return ['disponivel' => false,
                    'motivo' => 'O endereço do softphone não está configurado no servidor '
                              . '(SOFTPHONE_WS e SIP_DOMINIO). Rode o playbook do Ansible.'];
        }

        return [
            'disponivel' => true,
            'ws'         => $ws,
            'ramal'      => (string) $r['numero'],
            'nome'       => (string) $r['nome'],
            'senha'      => (string) $r['senha_sip'],
            'dominio'    => $dominio,
        ];
    }

    private function publico(array $u): array
    {
        return [
            'id'     => (int) $u['id'],
            'nome'   => $u['nome'],
            'usuario' => $u['usuario'],
            'email'  => $u['email'],
            'ramal'  => $u['ramal'],
            'setor'  => $u['setor'],
            'perfil' => [
                'chave' => $u['perfil_chave'],
                'nome'  => $u['perfil_nome'],
                'cor'   => $u['perfil_cor'],
            ],
        ];
    }

    private function ip(Request $req): string
    {
        $servidor = $req->getServerParams();
        return (string) ($servidor['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
