<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Senha;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/** Perfis, empresa e operações de cadastro que fogem do CRUD genérico. */
final class Cadastros
{
    /** GET /api/perfis — perfis com módulos e ações de cada um */
    public function perfis(Request $req, Response $res): Response
    {
        $perfis = Bd::todos('SELECT * FROM perfis ORDER BY id');

        foreach ($perfis as &$p) {
            $permissoes = Permissoes::doPerfil((int) $p['id']);
            $p['allow'] = $permissoes['allow'];
            $p['caps'] = $permissoes['caps'];
            $p['usuarios'] = (int) Bd::valor(
                'SELECT COUNT(*) FROM usuarios WHERE perfil_id = ?',
                [$p['id']]
            );
        }
        unset($p);

        return Resposta::json($res, ['dados' => $perfis]);
    }

    /** PUT /api/perfis/{id}/permissoes — grava a matriz de um perfil */
    public function salvarPermissoes(Request $req, Response $res, array $args): Response
    {
        $perfil = Bd::um('SELECT * FROM perfis WHERE id = ?', [$args['id']]);
        if ($perfil === null) {
            return Resposta::erro($res, 'Perfil não encontrado', 404);
        }

        $corpo = (array) $req->getParsedBody();
        $modulos = array_values(array_filter((array) ($corpo['allow'] ?? []), 'is_string'));
        $acoes = array_values(array_filter((array) ($corpo['caps'] ?? []), 'is_string'));

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            Bd::executar('DELETE FROM perfil_modulos WHERE perfil_id = ?', [$perfil['id']]);
            foreach ($modulos as $m) {
                Bd::executar(
                    'INSERT IGNORE INTO perfil_modulos (perfil_id, modulo) VALUES (?, ?)',
                    [$perfil['id'], $m]
                );
            }

            Bd::executar('DELETE FROM perfil_acoes WHERE perfil_id = ?', [$perfil['id']]);
            foreach ($acoes as $a) {
                Bd::executar(
                    'INSERT IGNORE INTO perfil_acoes (perfil_id, acao) VALUES (?, ?)',
                    [$perfil['id'], $a]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Resposta::erro($res, 'Falha ao gravar permissões: ' . $e->getMessage(), 500);
        }

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'editar',
            'admin.permissoes',
            (string) $perfil['chave'],
            ['modulos' => count($modulos), 'acoes' => count($acoes)]
        );

        return Resposta::json($res, ['allow' => $modulos, 'caps' => $acoes]);
    }

    /** GET /api/empresa */
    public function empresa(Request $req, Response $res): Response
    {
        $empresa = Bd::um('SELECT * FROM empresa WHERE id = 1') ?? [];
        $empresa['ramais_usados'] = (int) Bd::valor('SELECT COUNT(*) FROM ramais WHERE ativo = 1');
        $empresa['troncos'] = (int) Bd::valor('SELECT COUNT(*) FROM troncos WHERE ativo = 1');

        return Resposta::json($res, $empresa);
    }

    /** PUT /api/empresa */
    public function salvarEmpresa(Request $req, Response $res): Response
    {
        $permitidas = ['nome', 'razao_social', 'cnpj', 'endereco', 'telefone',
                       'fuso', 'idioma', 'plano', 'ramais_contratados', 'tema_padrao'];
        $corpo = (array) $req->getParsedBody();
        $dados = array_intersect_key($corpo, array_flip($permitidas));

        if ($dados === []) {
            return Resposta::erro($res, 'Nenhum campo válido enviado', 422);
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($dados)));
        Bd::executar("UPDATE empresa SET {$sets} WHERE id = 1", array_values($dados));

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'cfg.empresa', null, $dados);

        return $this->empresa($req, $res);
    }

    /** POST /api/usuarios/{id}/senha — define a senha de um usuário */
    public function trocarSenha(Request $req, Response $res, array $args): Response
    {
        $usuario = Bd::um('SELECT id, usuario FROM usuarios WHERE id = ?', [$args['id']]);
        if ($usuario === null) {
            return Resposta::erro($res, 'Usuário não encontrado', 404);
        }

        $senha = (string) ((array) $req->getParsedBody())['senha'] ?? '';
        if (mb_strlen($senha) < 10) {
            return Resposta::erro($res, 'A senha precisa de pelo menos 10 caracteres', 422);
        }

        Bd::executar(
            'UPDATE usuarios SET senha_hash = ?, tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?',
            [Senha::criar($senha), $usuario['id']]
        );
        Bd::executar('DELETE FROM sessoes WHERE usuario_id = ?', [$usuario['id']]);

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'editar',
            'admin.usuarios',
            (string) $usuario['usuario'],
            ['acao' => 'senha redefinida']
        );

        return Resposta::json($res, ['ok' => true]);
    }

    /** GET /api/ramais/{id}/credenciais — senha SIP, só para quem pode editar */
    public function credenciaisRamal(Request $req, Response $res, array $args): Response
    {
        $ramal = Bd::um('SELECT numero, senha_sip, transporte FROM ramais WHERE id = ?', [$args['id']]);
        if ($ramal === null) {
            return Resposta::erro($res, 'Ramal não encontrado', 404);
        }

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'ver_credencial',
            'conn.ramais',
            (string) $ramal['numero']
        );

        return Resposta::json($res, $ramal);
    }
}
