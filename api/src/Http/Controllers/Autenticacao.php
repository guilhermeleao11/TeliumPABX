<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Senha;
use Telium\Dominio\Totp;
use Telium\Dominio\Sessao;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Versao;
use Telium\Suporte\Resposta;

final class Autenticacao
{
    private const MAX_TENTATIVAS = 5;
    private const BLOQUEIO_MINUTOS = 15;

    /**
     * Bloqueio por conta não segura varredura: o atacante troca de
     * usuário a cada tentativa e nunca bloqueia ninguém. Este teto é por
     * origem, contando o que a auditoria já registra.
     */
    private const MAX_POR_IP = 20;
    private const JANELA_IP_MINUTOS = 10;

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

        // Origem com falhas demais entra em suspeita, mas ainda tem a
        // senha conferida: quem sabe a senha não pode ficar de fora
        // porque outra pessoa na mesma saída de internet errou a dela.
        // Só o palpite errado é que bate na porta fechada.
        $suspeito = $this->excedeuPorIp($ip);

        $usuario = Bd::um(
            'SELECT u.*, p.chave AS perfil_chave, p.nome AS perfil_nome, p.cor AS perfil_cor
               FROM usuarios u JOIN perfis p ON p.id = u.perfil_id
              WHERE u.usuario = ?',
            [$login]
        );

        // Mensagem genérica de propósito: não revela se o usuário existe.
        $generico = 'Usuário ou senha inválidos';

        if ($usuario === null) {
            // Gasta o mesmo tempo de um hash de verdade. Sem isto, um
            // usuário inexistente responde na hora e um existente demora
            // — e o relógio vira uma lista de contas válidas.
            Senha::verificar($senha, Senha::HASH_FALSO);
            Auditoria::registrar(null, 'login_falha', 'auth', $login, ['motivo' => 'inexistente'], $ip);

            return $suspeito ? $this->demais($res, $login, $ip) : Resposta::erro($res, $generico, 401);
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

            return $suspeito ? $this->demais($res, $login, $ip) : Resposta::erro($res, $generico, 401);
        }

        if ($usuario['status'] !== 'ativo') {
            return Resposta::erro($res, "Usuário {$usuario['status']}. Procure o administrador.", 403);
        }

        // Verificação em dois passos. Antes o código era só perguntado:
        // qualquer valor digitado passava, e quem ligava o recurso ficava
        // menos protegido do que quem não ligava.
        if ((int) $usuario['totp_ativo'] === 1) {
            $codigo = trim((string) ($corpo['codigo'] ?? ''));
            $segredo = (string) ($usuario['totp_secret'] ?? '');

            if ($segredo === '') {
                Auditoria::registrar($usuario, 'login_falha', 'auth', $login,
                                     ['motivo' => '2fa_sem_segredo'], $ip);

                return Resposta::erro(
                    $res,
                    'A verificação em dois passos está ligada nesta conta, mas sem segredo '
                    . 'cadastrado. Procure o administrador.',
                    403
                );
            }
            if ($codigo === '') {
                return Resposta::json($res, ['precisa_2fa' => true], 200);
            }

            $passo = Totp::passoUsado($segredo, $codigo);
            if ($passo === null) {
                $this->contarFalha($usuario, $login, $ip, '2fa_invalido');

                return Resposta::erro($res, 'Código de verificação inválido', 401);
            }
            // Um código vale trinta segundos; sem esta marca, quem o
            // interceptasse entraria de novo dentro da mesma janela.
            if ($passo <= (int) ($usuario['totp_ultimo_passo'] ?? 0)) {
                $this->contarFalha($usuario, $login, $ip, '2fa_repetido');

                return Resposta::erro(
                    $res,
                    'Este código já foi usado. Espere o próximo aparecer no aplicativo.',
                    401
                );
            }
            Bd::executar('UPDATE usuarios SET totp_ultimo_passo = ? WHERE id = ?',
                         [$passo, $usuario['id']]);
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
            'versao'     => Versao::NUMERO,
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

        // O endereço do WebSocket é resolvido no navegador, pela origem da
        // página: quem entra pelo IP e quem entra pelo nome chegam ao
        // mesmo lugar, com o certificado que já foi aceito. O que vem
        // daqui é só o rumo de quem preferir fixar outro endereço.
        return [
            'disponivel' => true,
            'ws'         => (string) Ambiente::get('SOFTPHONE_WS', ''),
            'ramal'      => (string) $r['numero'],
            'nome'       => (string) $r['nome'],
            'senha'      => (string) $r['senha_sip'],
            'dominio'    => (string) Ambiente::get('SIP_DOMINIO', ''),
            'ice'        => $this->ice(),
        ];
    }

    /**
     * Servidores de ICE para o navegador.
     *
     * Sem STUN o navegador anuncia só o endereço da rede local dele:
     * dentro da empresa a chamada fecha, de fora o áudio some num lado
     * só. O TURN entra quando a rede do usuário bloqueia UDP direto.
     *
     * @return array<int,array<string,mixed>>
     */
    private function ice(): array
    {
        $lista = [];

        $stun = trim((string) Ambiente::get('SOFTPHONE_STUN', 'stun:stun.l.google.com:19302'));
        if ($stun !== '') {
            $lista[] = ['urls' => $stun];
        }

        $turn = trim((string) Ambiente::get('SOFTPHONE_TURN', ''));
        if ($turn !== '') {
            $lista[] = [
                'urls'       => $turn,
                'username'   => (string) Ambiente::get('SOFTPHONE_TURN_USUARIO', ''),
                'credential' => (string) Ambiente::get('SOFTPHONE_TURN_SENHA', ''),
            ];
        }

        return $lista;
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

    /**
     * Falhas demais vindas desta origem na última janela?
     *
     * A auditoria já grava cada login_falha com IP e horário, então a
     * contagem sai de lá em vez de inventar uma segunda tabela.
     */
    private function excedeuPorIp(string $ip): bool
    {
        if ($ip === '' || $ip === '0.0.0.0') {
            return false;
        }

        $falhas = (int) Bd::valor(
            "SELECT COUNT(*) FROM auditoria
              WHERE ip = ? AND acao = 'login_falha'
                AND criado_em > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$ip, self::JANELA_IP_MINUTOS]
        );

        return $falhas >= self::MAX_POR_IP;
    }

    /** Resposta para quem errou vindo de uma origem já marcada. */
    private function demais(Response $res, string $login, string $ip): Response
    {
        Auditoria::registrar(null, 'login_bloqueio_ip', 'auth', $login, [], $ip);

        return Resposta::erro(
            $res,
            'Tentativas demais desta origem. Tente de novo em alguns minutos.',
            429
        );
    }

    /** Soma uma tentativa errada na conta e bloqueia no limite. */
    private function contarFalha(array $usuario, string $login, string $ip, string $motivo): void
    {
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

        Auditoria::registrar($usuario, 'login_falha', 'auth', $login,
                             ['motivo' => $motivo, 'tentativa' => $tentativas], $ip);
    }

    private function ip(Request $req): string
    {
        $servidor = $req->getServerParams();
        return (string) ($servidor['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
