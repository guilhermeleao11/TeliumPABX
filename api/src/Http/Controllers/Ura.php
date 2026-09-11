<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * URA: as entradas do menu.
 *
 * A tela monta a URA e as teclas na mesma gaveta, então salvar uma tecla
 * por vez não serve — aqui a lista chega inteira e substitui a anterior.
 */
final class Ura
{
    /** Teclas aceitas: um dígito, * ou #, ou um padrão de dialplan. */
    private const TECLA = '/^(?:[0-9*#]{1,4}|_[0-9*#XZNxzn._\[\]-]{1,12})$/';

    /** GET /api/ura/{id}/opcoes */
    public function opcoes(Request $req, Response $res, array $args): Response
    {
        $ura = Bd::um('SELECT * FROM ura WHERE id = ?', [$args['id']]);
        if ($ura === null) {
            return Resposta::erro($res, 'URA não encontrada', 404);
        }

        return Resposta::json($res, [
            'ura' => $ura,
            'opcoes' => Bd::todos(
                'SELECT * FROM ura_opcoes WHERE ura_id = ? ORDER BY ordem, tecla',
                [$ura['id']]
            ),
        ]);
    }

    /** PUT /api/ura/{id}/opcoes — troca a lista inteira */
    public function salvarOpcoes(Request $req, Response $res, array $args): Response
    {
        $ura = Bd::um('SELECT * FROM ura WHERE id = ?', [$args['id']]);
        if ($ura === null) {
            return Resposta::erro($res, 'URA não encontrada', 404);
        }

        $corpo = (array) $req->getParsedBody();
        $lista = array_values((array) ($corpo['opcoes'] ?? []));

        $vistas = [];
        foreach ($lista as $i => $o) {
            $tecla = trim((string) ($o['tecla'] ?? ''));
            $tipo = trim((string) ($o['destino_tipo'] ?? ''));

            if ($tecla === '') {
                return Resposta::erro($res, 'Uma das entradas está sem os dígitos.', 422,
                                      ['campo' => "tecla-{$i}"]);
            }
            if (preg_match(self::TECLA, $tecla) !== 1) {
                return Resposta::erro(
                    $res,
                    "\"{$tecla}\" não serve como entrada. Use os dígitos que o cliente aperta "
                    . '(1, 0, *, #) ou um padrão do dialplan começando com _ (por exemplo _2XX).',
                    422,
                    ['campo' => "tecla-{$i}"]
                );
            }
            if (isset($vistas[$tecla])) {
                return Resposta::erro($res, "A entrada \"{$tecla}\" aparece duas vezes.", 422,
                                      ['campo' => "tecla-{$i}"]);
            }
            $vistas[$tecla] = true;

            if ($tipo === '') {
                return Resposta::erro(
                    $res,
                    "A entrada \"{$tecla}\" está sem destino. Escolha para onde ela manda a chamada.",
                    422,
                    ['campo' => "destino-{$i}"]
                );
            }
        }

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            Bd::executar('DELETE FROM ura_opcoes WHERE ura_id = ?', [$ura['id']]);

            foreach ($lista as $i => $o) {
                Bd::executar(
                    'INSERT INTO ura_opcoes (ura_id, tecla, rotulo, destino_tipo, destino_valor, ordem)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $ura['id'],
                        trim((string) $o['tecla']),
                        trim((string) ($o['rotulo'] ?? '')) ?: 'Opção ' . trim((string) $o['tecla']),
                        trim((string) $o['destino_tipo']),
                        trim((string) ($o['destino_valor'] ?? '')),
                        (int) ($o['ordem'] ?? ($i + 1) * 10),
                    ]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            return Resposta::erro($res, 'Não foi possível gravar as entradas: ' . $e->getMessage(), 400);
        }

        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'apps.ura',
                             (string) $ura['nome'], ['entradas' => count($lista)]);

        return Resposta::json($res, ['ok' => true, 'entradas' => count($lista)]);
    }
}
