<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Agenda de contatos.
 *
 * Duas agendas na mesma tabela: a corporativa (usuario_id NULL), que todo
 * mundo enxerga, e a pessoal de cada usuário. Quem tem o módulo
 * admin.contatos administra a corporativa; os demais só mexem na própria.
 * Por isso esta agenda não usa o CRUD genérico — a regra é por linha.
 */
final class Contatos
{
    private const FOTO_MAX = 4 * 1024 * 1024;
    private const FOTO_LADO = 512;
    private const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png',
                           'image/webp' => 'webp', 'image/gif' => 'gif'];

    private const CAMPOS = [
        'nome', 'empresa', 'cargo', 'departamento', 'numero', 'celular', 'telefone',
        'ramal_interno', 'email', 'email_alt', 'site', 'links', 'endereco', 'complemento',
        'bairro', 'cidade', 'uf', 'cep', 'pais', 'aniversario', 'notas', 'grupo',
    ];

    /** GET /api/contatos */
    public function listar(Request $req, Response $res): Response
    {
        $p = $req->getQueryParams();
        $usuario = $req->getAttribute('usuario');
        $meuId = (int) ($usuario['id'] ?? 0);
        $admin = $this->administra($req);

        // Visibilidade: a agenda corporativa é de todos; a pessoal é só de quem é.
        $where = $admin && ($p['escopo'] ?? '') === 'todos'
            ? ['1 = 1']
            : ['(c.usuario_id IS NULL OR c.usuario_id = ?)'];
        $args = $admin && ($p['escopo'] ?? '') === 'todos' ? [] : [$meuId];

        if (($p['escopo'] ?? '') === 'corporativo') {
            $where = ['c.usuario_id IS NULL'];
            $args = [];
        } elseif (($p['escopo'] ?? '') === 'pessoal') {
            $where = ['c.usuario_id = ?'];
            $args = [$meuId];
        }

        if (($p['q'] ?? '') !== '') {
            $where[] = '(c.nome LIKE ? OR c.empresa LIKE ? OR c.cargo LIKE ? OR c.numero LIKE ?
                         OR c.celular LIKE ? OR c.telefone LIKE ? OR c.email LIKE ?)';
            $args = [...$args, ...array_fill(0, 7, '%' . $p['q'] . '%')];
        }
        foreach (['grupo', 'empresa'] as $coluna) {
            if (($p[$coluna] ?? '') !== '') {
                $where[] = "c.{$coluna} = ?";
                $args[] = $p[$coluna];
            }
        }
        if (($p['favoritos'] ?? '') === '1') {
            $where[] = 'c.favorito = 1';
        }

        $filtro = ' WHERE ' . implode(' AND ', $where);
        $dados = Bd::todos(
            "SELECT c.*, u.nome AS dono_nome
               FROM contatos c
          LEFT JOIN usuarios u ON u.id = c.usuario_id
             {$filtro}
           ORDER BY c.favorito DESC, c.nome",
            $args
        );

        foreach ($dados as &$c) {
            $c['escopo'] = $c['usuario_id'] === null ? 'corporativo' : 'pessoal';
            $c['meu'] = (int) $c['usuario_id'] === $meuId;
            $c['editavel'] = $c['usuario_id'] === null ? $admin : $c['meu'];
            $c['foto_url'] = $c['foto'] ? $this->urlFoto((string) $c['foto']) : null;
        }
        unset($c);

        return Resposta::json($res, [
            'dados' => $dados,
            'grupos' => array_column(Bd::todos(
                'SELECT DISTINCT grupo FROM contatos WHERE grupo IS NOT NULL AND grupo <> "" ORDER BY grupo'
            ), 'grupo'),
            'empresas' => array_column(Bd::todos(
                'SELECT DISTINCT empresa FROM contatos WHERE empresa IS NOT NULL AND empresa <> "" ORDER BY empresa'
            ), 'empresa'),
            'administra' => $admin,
            'fotos_ok' => $this->diretorioFotos() !== null,
        ]);
    }

    /** GET /api/contatos/{id} */
    public function obter(Request $req, Response $res, array $args): Response
    {
        $c = $this->carregar($req, (string) $args['id']);
        if ($c === null) {
            return Resposta::erro($res, 'Contato não encontrado', 404);
        }
        $c['foto_url'] = $c['foto'] ? $this->urlFoto((string) $c['foto']) : null;

        return Resposta::json($res, $c);
    }

    /** POST /api/contatos */
    public function criar(Request $req, Response $res): Response
    {
        $corpo = (array) $req->getParsedBody();
        $usuario = $req->getAttribute('usuario');

        $problema = $this->validar($corpo);
        if ($problema !== null) {
            return Resposta::erro($res, $problema['mensagem'], 422, ['campo' => $problema['campo']]);
        }

        $corporativo = ($corpo['escopo'] ?? 'pessoal') === 'corporativo';
        if ($corporativo && !$this->administra($req)) {
            return Resposta::erro(
                $res,
                'Seu perfil só pode criar contatos na agenda pessoal. '
                . 'A agenda da empresa é mantida por quem tem o Gerenciador de Contatos.',
                403,
                ['campo' => 'escopo']
            );
        }

        $colunas = ['usuario_id', ...self::CAMPOS, 'favorito', 'ativo'];
        $valores = [$corporativo ? null : (int) ($usuario['id'] ?? 0)];
        foreach (self::CAMPOS as $campo) {
            $valores[] = $this->valor($corpo, $campo);
        }
        $valores[] = $this->flag($corpo, 'favorito');
        $valores[] = $this->flag($corpo, 'ativo', true);

        Bd::executar(
            'INSERT INTO contatos (' . implode(', ', $colunas) . ') VALUES ('
            . implode(', ', array_fill(0, count($colunas), '?')) . ')',
            $valores
        );
        $id = (int) Bd::conexao()->lastInsertId();

        Auditoria::registrar($usuario, 'criar', $corporativo ? 'admin.contatos' : 'pcu.contatos',
                             (string) ($corpo['nome'] ?? $id));

        return Resposta::json($res, ['id' => $id], 201);
    }

    /** PUT /api/contatos/{id} */
    public function atualizar(Request $req, Response $res, array $args): Response
    {
        $c = $this->carregar($req, (string) $args['id']);
        if ($c === null) {
            return Resposta::erro($res, 'Contato não encontrado', 404);
        }
        if (!$this->podeMexer($req, $c)) {
            return Resposta::erro($res, $this->recusa($c), 403);
        }

        $corpo = (array) $req->getParsedBody();
        $problema = $this->validar($corpo, $c);
        if ($problema !== null) {
            return Resposta::erro($res, $problema['mensagem'], 422, ['campo' => $problema['campo']]);
        }

        $sets = [];
        $valores = [];
        foreach (self::CAMPOS as $campo) {
            if (!array_key_exists($campo, $corpo)) {
                continue;
            }
            $sets[] = "{$campo} = ?";
            $valores[] = $this->valor($corpo, $campo);
        }
        foreach (['favorito', 'ativo'] as $flag) {
            if (array_key_exists($flag, $corpo)) {
                $sets[] = "{$flag} = ?";
                $valores[] = $this->flag($corpo, $flag);
            }
        }

        // Mover entre as agendas é privilégio de quem administra a corporativa.
        if (array_key_exists('escopo', $corpo) && $this->administra($req)) {
            $sets[] = 'usuario_id = ?';
            $valores[] = $corpo['escopo'] === 'corporativo'
                ? null
                : ($c['usuario_id'] ?? (int) ($req->getAttribute('usuario')['id'] ?? 0));
        }

        if ($sets === []) {
            return Resposta::json($res, ['ok' => true, 'detalhe' => 'Nada a mudar.']);
        }

        $valores[] = $c['id'];
        Bd::executar('UPDATE contatos SET ' . implode(', ', $sets) . ' WHERE id = ?', $valores);

        Auditoria::registrar($req->getAttribute('usuario'), 'editar',
                             $c['usuario_id'] === null ? 'admin.contatos' : 'pcu.contatos',
                             (string) $c['nome']);

        return Resposta::json($res, ['ok' => true]);
    }

    /** DELETE /api/contatos/{id} */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $c = $this->carregar($req, (string) $args['id']);
        if ($c === null) {
            return Resposta::erro($res, 'Contato não encontrado', 404);
        }
        if (!$this->podeMexer($req, $c)) {
            return Resposta::erro($res, $this->recusa($c), 403);
        }

        $this->apagarFoto($c);
        Bd::executar('DELETE FROM contatos WHERE id = ?', [$c['id']]);

        Auditoria::registrar($req->getAttribute('usuario'), 'excluir',
                             $c['usuario_id'] === null ? 'admin.contatos' : 'pcu.contatos',
                             (string) $c['nome']);

        return Resposta::json($res, ['removido' => true]);
    }

    /** POST /api/contatos/{id}/foto */
    public function foto(Request $req, Response $res, array $args): Response
    {
        $c = $this->carregar($req, (string) $args['id']);
        if ($c === null) {
            return Resposta::erro($res, 'Contato não encontrado', 404);
        }
        if (!$this->podeMexer($req, $c)) {
            return Resposta::erro($res, $this->recusa($c), 403);
        }

        $enviado = $req->getUploadedFiles()['foto'] ?? null;
        if (!$enviado instanceof UploadedFileInterface || $enviado->getError() !== UPLOAD_ERR_OK) {
            return Resposta::erro($res, 'Nenhuma imagem recebida no campo "foto".', 422, ['campo' => 'foto']);
        }
        if ($enviado->getSize() > self::FOTO_MAX) {
            return Resposta::erro($res, 'A imagem passa de 4 MB. Envie uma menor.', 422, ['campo' => 'foto']);
        }

        $dir = $this->diretorioFotos();
        if ($dir === null) {
            return Resposta::erro(
                $res,
                'O diretório de fotos não existe ou não é gravável no servidor ('
                . $this->caminhoFotos() . '). Rode o playbook do Ansible para criá-lo.',
                500
            );
        }

        $temp = tempnam(sys_get_temp_dir(), 'telium-foto-');
        $enviado->moveTo($temp);

        $info = @getimagesize($temp);
        $mime = $info['mime'] ?? '';
        if (!isset(self::TIPOS[$mime])) {
            @unlink($temp);

            return Resposta::erro($res, 'Formato não aceito. Envie JPG, PNG, WebP ou GIF.', 422,
                                  ['campo' => 'foto']);
        }

        $ext = self::TIPOS[$mime];
        $arquivo = sprintf('%d-%s.%s', (int) $c['id'], bin2hex(random_bytes(4)), $ext);

        if (!$this->reduzir($temp, "{$dir}/{$arquivo}", $mime)) {
            @unlink($temp);

            return Resposta::erro($res, 'Não foi possível gravar a imagem no servidor.', 500);
        }
        @unlink($temp);
        @chmod("{$dir}/{$arquivo}", 0644);

        $this->apagarFoto($c);
        Bd::executar('UPDATE contatos SET foto = ? WHERE id = ?', [$arquivo, $c['id']]);

        return Resposta::json($res, ['foto' => $arquivo, 'foto_url' => $this->urlFoto($arquivo)]);
    }

    /** DELETE /api/contatos/{id}/foto */
    public function removerFoto(Request $req, Response $res, array $args): Response
    {
        $c = $this->carregar($req, (string) $args['id']);
        if ($c === null) {
            return Resposta::erro($res, 'Contato não encontrado', 404);
        }
        if (!$this->podeMexer($req, $c)) {
            return Resposta::erro($res, $this->recusa($c), 403);
        }

        $this->apagarFoto($c);
        Bd::executar('UPDATE contatos SET foto = NULL WHERE id = ?', [$c['id']]);

        return Resposta::json($res, ['removido' => true]);
    }

    // ------------------------------------------------------------------
    private function carregar(Request $req, string $id): ?array
    {
        $c = Bd::um('SELECT * FROM contatos WHERE id = ?', [$id]);
        if ($c === null) {
            return null;
        }

        // Contato pessoal de outra pessoa não existe para quem não administra.
        $meu = (int) $c['usuario_id'] === (int) ($req->getAttribute('usuario')['id'] ?? 0);
        if ($c['usuario_id'] !== null && !$meu && !$this->administra($req)) {
            return null;
        }

        return $c;
    }

    private function administra(Request $req): bool
    {
        return Permissoes::podeModulo($req->getAttribute('allow', []), 'admin.contatos');
    }

    private function podeMexer(Request $req, array $c): bool
    {
        if ($c['usuario_id'] === null) {
            return $this->administra($req);
        }

        return (int) $c['usuario_id'] === (int) ($req->getAttribute('usuario')['id'] ?? 0)
            || $this->administra($req);
    }

    private function recusa(array $c): string
    {
        return $c['usuario_id'] === null
            ? 'Este contato é da agenda da empresa. Só quem tem o Gerenciador de Contatos pode alterá-lo.'
            : 'Este contato é da agenda pessoal de outro usuário.';
    }

    /** @return array{campo:string, mensagem:string}|null */
    private function validar(array $corpo, ?array $atual = null): ?array
    {
        $nome = trim((string) ($corpo['nome'] ?? $atual['nome'] ?? ''));
        if ($nome === '') {
            return ['campo' => 'nome', 'mensagem' => 'O contato precisa de um nome.'];
        }

        $temNumero = false;
        foreach (['numero', 'celular', 'telefone', 'ramal_interno'] as $campo) {
            if (trim((string) ($corpo[$campo] ?? $atual[$campo] ?? '')) !== '') {
                $temNumero = true;
            }
        }
        $email = trim((string) ($corpo['email'] ?? $atual['email'] ?? ''));
        if (!$temNumero && $email === '') {
            return ['campo' => 'numero',
                    'mensagem' => 'Informe pelo menos um telefone (principal, celular, fixo ou ramal) '
                                . 'ou um e-mail — senão não há como falar com este contato.'];
        }

        foreach (['email', 'email_alt'] as $campo) {
            $v = trim((string) ($corpo[$campo] ?? ''));
            if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return ['campo' => $campo, 'mensagem' => 'E-mail em formato inválido.'];
            }
        }

        $aniversario = trim((string) ($corpo['aniversario'] ?? ''));
        if ($aniversario !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $aniversario)) {
            return ['campo' => 'aniversario', 'mensagem' => 'Use uma data no formato dia/mês/ano.'];
        }

        return null;
    }

    private function valor(array $corpo, string $campo): ?string
    {
        $v = trim((string) ($corpo[$campo] ?? ''));

        return $v === '' ? null : $v;
    }

    private function flag(array $corpo, string $chave, bool $padrao = false): int
    {
        $v = $corpo[$chave] ?? $padrao;

        return (is_bool($v) ? $v : in_array($v, [1, '1', 'true', true], true)) ? 1 : 0;
    }

    private function caminhoFotos(): string
    {
        return rtrim((string) Ambiente::get('FOTOS_DIR', '/opt/telium/web/uploads/contatos'), '/');
    }

    private function diretorioFotos(): ?string
    {
        $dir = $this->caminhoFotos();

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    private function urlFoto(string $arquivo): string
    {
        return rtrim((string) Ambiente::get('FOTOS_URL', '/uploads/contatos'), '/') . '/' . $arquivo;
    }

    private function apagarFoto(array $c): void
    {
        if (($c['foto'] ?? null) === null) {
            return;
        }
        $dir = $this->diretorioFotos();
        if ($dir !== null) {
            @unlink($dir . '/' . basename((string) $c['foto']));
        }
    }

    /**
     * Guarda a foto num tamanho razoável. Sem a extensão gd, copia o
     * original — o limite de 4 MB já evita o pior.
     */
    private function reduzir(string $origem, string $destino, string $mime): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return (bool) @copy($origem, $destino);
        }

        $img = @imagecreatefromstring((string) file_get_contents($origem));
        if ($img === false) {
            return (bool) @copy($origem, $destino);
        }

        $l = imagesx($img);
        $a = imagesy($img);
        $maior = max($l, $a);

        if ($maior > self::FOTO_LADO) {
            $escala = self::FOTO_LADO / $maior;
            $novo = imagescale($img, (int) round($l * $escala), (int) round($a * $escala));
            if ($novo !== false) {
                imagedestroy($img);
                $img = $novo;
            }
        }

        $ok = match ($mime) {
            'image/png'  => imagepng($img, $destino, 6),
            'image/webp' => imagewebp($img, $destino, 82),
            'image/gif'  => imagegif($img, $destino),
            default      => imagejpeg($img, $destino, 85),
        };
        imagedestroy($img);

        return (bool) $ok;
    }
}
