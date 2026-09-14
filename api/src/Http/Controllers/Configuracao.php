<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Gerador\Aplicador;
use Telium\Gerador\Conferencia;
use Telium\Gerador\Gerador;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;
use Telium\Suporte\Esquema;
use Telium\Suporte\Resposta;

/** Geração e aplicação dos .conf do Asterisk. */
final class Configuracao
{
    /** GET /api/config/estado */
    public function estado(Request $req, Response $res): Response
    {
        $ultima = Bd::um(
            'SELECT id, sucesso, criado_em, reloads FROM config_aplicacoes ORDER BY id DESC LIMIT 1'
        );

        // Quantos arquivos mudariam se aplicássemos agora. É o que a barra
        // mostra, e sai de uma geração em memória — nada é escrito aqui.
        $pendentes = 0;
        try {
            foreach ((new Gerador())->simular() as $estado) {
                if ($estado !== 'inalterado') {
                    $pendentes++;
                }
            }
        } catch (\Throwable) {
            // Sem acesso ao diretório, a contagem fica em zero e a barra
            // mostra o texto genérico — o aviso continua valendo.
        }

        return Resposta::json($res, [
            'pendente'           => (new Gerador())->pendente(),
            'arquivos_pendentes' => $pendentes,
            'ultima_aplicacao'   => $ultima,
        ]);
    }

    /**
     * GET /api/config/arquivos — o que a central escreve, e o que é seu.
     *
     * Dois tipos: o que a API gera a partir do cadastro, e reescreve a
     * cada aplicação, e o que fica reservado para personalização e o
     * gerador nunca toca. Saber de cabeça qual é qual é o que evita
     * alguém editar à mão um arquivo que será sobrescrito.
     */
    public function arquivos(Request $req, Response $res): Response
    {
        $g = new Gerador();
        $dir = rtrim((string) Ambiente::get('ASTERISK_GERADO_DIR', '/etc/asterisk/telium'), '/');

        $estados = [];
        try {
            $estados = $g->simular();
        } catch (\Throwable) {
            // Sem acesso ao diretório a lista sai vazia; o aviso de
            // permissão abaixo é que explica o motivo.
        }

        $gerados = [];
        foreach ($estados as $nome => $estado) {
            $caminho = "{$dir}/{$nome}";
            $gerados[] = [
                'arquivo' => $nome,
                'estado'  => $estado,
                'bytes'   => is_file($caminho) ? (int) filesize($caminho) : 0,
                'mudado_em' => is_file($caminho) ? date('Y-m-d H:i:s', (int) filemtime($caminho)) : null,
            ];
        }

        $personalizados = [];
        foreach (glob("{$dir}/*_custom.conf") ?: [] as $caminho) {
            $personalizados[] = [
                'arquivo' => basename($caminho),
                'bytes'   => (int) filesize($caminho),
                'mudado_em' => date('Y-m-d H:i:s', (int) filemtime($caminho)),
                'tem_conteudo' => trim((string) preg_replace('/^\s*;.*$/m', '', (string) file_get_contents($caminho))) !== '',
            ];
        }

        return Resposta::json($res, [
            'diretorio'      => $dir,
            'gerados'        => $gerados,
            'personalizados' => $personalizados,
            'permissao'      => $g->problemaDePermissao(),
            'historico'      => Bd::todos(
                'SELECT id, sucesso, criado_em, reloads, arquivos
                   FROM config_aplicacoes ORDER BY id DESC LIMIT 10'
            ),
        ]);
    }

    /** POST /api/config/gerar */
    public function gerar(Request $req, Response $res): Response
    {
        try {
            $arquivos = (new Gerador())->gerar();
        } catch (\Throwable $e) {
            return Resposta::erro($res, Esquema::explicarErro($e) ?? $e->getMessage(), 500);
        }

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            'gerar',
            'config',
            null,
            ['arquivos' => array_keys($arquivos)],
        );

        return Resposta::json($res, [
            'arquivos'  => $arquivos,
            'problemas' => Conferencia::problemas(),
        ]);
    }

    /** POST /api/config/aplicar — gera e recarrega o Asterisk. */
    public function aplicar(Request $req, Response $res): Response
    {
        $usuario = $req->getAttribute('usuario');

        try {
            $arquivos = (new Gerador())->gerar();
        } catch (\Throwable $e) {
            return Resposta::erro($res, Esquema::explicarErro($e) ?? $e->getMessage(), 500);
        }

        $resultado = (new Aplicador())->aplicar(
            $usuario['id'] ?? null,
            array_keys($arquivos)
        );

        Auditoria::registrar($usuario, 'aplicar', 'config', null, $resultado['etapas']);

        return Resposta::json($res, [
            'arquivos'  => $arquivos,
            'problemas' => Conferencia::problemas(),
            'aplicado' => $resultado['sucesso'],
            'etapas'   => $resultado['etapas'],
            'saida'    => $resultado['saida'],
        ], $resultado['sucesso'] ? 200 : 500);
    }
}
