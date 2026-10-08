<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\CallCenter as Cc;
use Telium\Dominio\CodigosDiscagem;
use Telium\Dominio\Permissoes;
use Telium\Dominio\RelatorioFilas;
use Telium\Servico\TempoReal;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * API do call center: o painel do agente, os comandos do supervisor, o
 * cadastro de agentes e a configuração do módulo.
 *
 * Nada aqui guarda estado de agente: entrar, pausar e sair vão direto
 * ao Asterisk, pela mesma classe que o telefone e o serviço de tempo
 * real usam.
 */
final class CallCenter
{
    /** Os códigos do telefone que a tela do módulo deixa trocar. */
    private const CODIGOS = ['cc_login', 'cc_logout', 'cc_volta'];

    // ------------------------------------------------------------------
    // Estado ao vivo
    // ------------------------------------------------------------------

    /**
     * GET /api/cc/estado — a mesma foto que o serviço empurra.
     *
     * É o que a tela usa quando o fluxo não está disponível (serviço
     * parado, proxy que corta conexão longa): pergunta a cada poucos
     * segundos, com atraso, mas sem ficar cega.
     */
    public function estado(Request $req, Response $res): Response
    {
        $usuario = $req->getAttribute('usuario');
        // O painel do agente pede a visão de agente mesmo para quem também
        // é supervisor: senão "eu" seria o primeiro agente da lista.
        $supervisor = Permissoes::podeModulo($req->getAttribute('allow', []), 'cc.supervisor')
            && ($req->getQueryParams()['visao'] ?? '') !== 'agente';
        $agente = Cc::agenteDoUsuario((int) $usuario['id']);

        if (!$supervisor && $agente === null) {
            return Resposta::erro($res, 'Sua conta não é de agente de call center.', 403);
        }

        try {
            $estado = Cc::compartilhado()->estado();
        } catch (\Throwable $e) {
            return Resposta::erro($res, 'Não foi possível falar com o Asterisk: ' . $e->getMessage(), 503);
        }

        $motivos = array_column(Bd::todos('SELECT nome, limite_minutos FROM cc_pausas_motivos'), null, 'nome');
        $visao = TempoReal::visao($estado, TempoReal::numerosDeHoje(), $motivos, $supervisor,
                                  $agente !== null ? (int) $agente['id'] : null);
        $visao['ao_vivo'] = false;

        return Resposta::json($res, $visao);
    }

    // ------------------------------------------------------------------
    // Painel do agente
    // ------------------------------------------------------------------

    /** GET /api/cc/eu — quem sou, onde posso entrar, o que tenho a tabular. */
    public function eu(Request $req, Response $res): Response
    {
        $usuario = $req->getAttribute('usuario');
        $agente = Cc::agenteDoUsuario((int) $usuario['id']);
        if ($agente === null) {
            return Resposta::json($res, ['agente' => null]);
        }

        $id = (int) $agente['id'];
        $filas = Cc::filasDoAgente($id);
        $numeros = array_column($filas, 'numero');

        $tabulacoes = [];
        if ($numeros !== []) {
            $marcas = implode(',', array_fill(0, count($numeros), '?'));
            $tabulacoes = Bd::todos(
                "SELECT t.id, t.nome, t.grupo, f.numero AS fila
                   FROM cc_tabulacoes t LEFT JOIN filas f ON f.id = t.fila_id
                  WHERE t.ativo = 1 AND (t.fila_id IS NULL OR f.numero IN ({$marcas}))
               ORDER BY t.grupo, t.ordem, t.nome",
                $numeros
            );
        }

        return Resposta::json($res, [
            'agente' => [
                'id'        => $id,
                'nome'      => $agente['nome'],
                'matricula' => $agente['matricula'],
                'tem_pin'   => $agente['pin_hash'] !== null && $agente['pin_hash'] !== '',
                // Onde ele costuma sentar: o do cadastro de agente, ou o
                // ramal da conta do console.
                'ramal_sugerido' => $agente['ramal_padrao'] ?: ($agente['ramal_usuario'] ?: ''),
            ],
            'filas'      => $filas,
            'motivos'    => Bd::todos(
                'SELECT id, nome, codigo, limite_minutos, produtiva FROM cc_pausas_motivos
                  WHERE ativo = 1 AND sistema = 0 ORDER BY ordem, nome'
            ),
            'tabulacoes' => $tabulacoes,
            'pendentes'  => self::comContato((int) $usuario['id'], self::pendentes($id)),
            // Quem está na linha agora: o atendimento aberto mais recente.
            'atual'      => self::comContato((int) $usuario['id'], Bd::todos(
                'SELECT a.id, a.fila, f.nome AS fila_nome, a.numero, a.nome, a.espera_seg, a.atendido_em,
                        UNIX_TIMESTAMP(a.atendido_em) AS desde
                   FROM cc_atendimentos a LEFT JOIN filas f ON f.numero = a.fila
                  WHERE a.agente_id = ? AND a.encerrado_em IS NULL AND a.atendido_em >= NOW() - INTERVAL 4 HOUR
               ORDER BY a.atendido_em DESC LIMIT 1',
                [$id]
            ))[0] ?? null,
            'recentes'   => self::comContato((int) $usuario['id'], Bd::todos(
                'SELECT a.id, a.fila, a.numero, a.nome, a.espera_seg, a.atendido_em, a.encerrado_em,
                        a.tabulado_em, a.tabulacao_id, t.nome AS tabulacao, a.observacao
                   FROM cc_atendimentos a LEFT JOIN cc_tabulacoes t ON t.id = a.tabulacao_id
                  WHERE a.agente_id = ? AND a.atendido_em >= CURDATE()
               ORDER BY a.atendido_em DESC LIMIT 30',
                [$id]
            )),
            'codigos'    => self::codigosDoTelefone(),
        ]);
    }

    /**
     * O contato da agenda de quem ligou, quando o número está nela.
     *
     * O agente vê "Maria Souza — Empresa X" em vez de um número solto.
     * A comparação é pelos dígitos: a agenda guarda "(11) 98765-4321" e
     * a chamada chega como "11987654321".
     *
     * @param list<array<string,mixed>> $linhas
     * @return list<array<string,mixed>>
     */
    private static function comContato(int $usuarioId, array $linhas): array
    {
        foreach ($linhas as &$l) {
            $digitos = preg_replace('/\D/', '', (string) ($l['numero'] ?? '')) ?? '';
            $l['contato'] = null;
            if (strlen($digitos) < 4) {
                continue;
            }
            $c = Bd::um(
                // A agenda corporativa e a pessoal DESTE agente — a pessoal
                // dos colegas não é dele para ler.
                "SELECT id, nome, empresa FROM contatos
                  WHERE ativo = 1 AND (usuario_id IS NULL OR usuario_id = ?) AND (
                        REGEXP_REPLACE(IFNULL(numero,''), '[^0-9]', '') = ?
                     OR REGEXP_REPLACE(IFNULL(celular,''), '[^0-9]', '') = ?
                     OR REGEXP_REPLACE(IFNULL(telefone,''), '[^0-9]', '') = ?)
                  ORDER BY usuario_id IS NULL
                  LIMIT 1",
                [$usuarioId, $digitos, $digitos, $digitos]
            );
            if ($c !== null) {
                $l['contato'] = ['nome' => $c['nome'], 'empresa' => $c['empresa']];
            }
        }
        unset($l);

        return $linhas;
    }

    /** Atendimentos do agente que pedem tabulação e ainda não têm. */
    private static function pendentes(int $agenteId): array
    {
        return Bd::todos(
            'SELECT a.id, a.fila, a.numero, a.nome, a.atendido_em, a.encerrado_em
               FROM cc_atendimentos a JOIN filas f ON f.numero = a.fila
              WHERE a.agente_id = ? AND a.tabulado_em IS NULL AND f.tabulacao_obrigatoria = 1
                AND a.atendido_em >= NOW() - INTERVAL 1 DAY
           ORDER BY a.atendido_em',
            [$agenteId]
        );
    }

    /** POST /api/cc/eu/entrar {ramal} */
    public function entrar(Request $req, Response $res): Response
    {
        $agente = $this->meuAgente($req);
        if ($agente === null) {
            return Resposta::erro($res, 'Sua conta não é de agente de call center.', 403);
        }
        $ramal = preg_replace('/\D/', '', (string) (((array) $req->getParsedBody())['ramal'] ?? '')) ?? '';
        if ($ramal === '') {
            return Resposta::erro($res, 'Informe o ramal em que você está.', 422);
        }

        return $this->executar($res, fn () => Cc::compartilhado()->logar((int) $agente['id'], $ramal));
    }

    /** POST /api/cc/eu/sair */
    public function sair(Request $req, Response $res): Response
    {
        $agente = $this->meuAgente($req);

        return $agente === null
            ? Resposta::erro($res, 'Sua conta não é de agente de call center.', 403)
            : $this->executar($res, fn () => ['filas' => Cc::compartilhado()->deslogar((int) $agente['id'])]);
    }

    /** POST /api/cc/eu/pausa {motivo_id} */
    public function pausar(Request $req, Response $res): Response
    {
        $agente = $this->meuAgente($req);
        if ($agente === null) {
            return Resposta::erro($res, 'Sua conta não é de agente de call center.', 403);
        }

        $motivo = self::motivo((int) (((array) $req->getParsedBody())['motivo_id'] ?? 0), false);
        if ($motivo === null) {
            return Resposta::erro($res, 'Escolha um motivo de pausa.', 422);
        }

        return $this->executar($res, function () use ($agente, $motivo) {
            Cc::compartilhado()->pausar((int) $agente['id'], $motivo);

            return ['motivo' => $motivo];
        });
    }

    /** POST /api/cc/eu/volta */
    public function voltar(Request $req, Response $res): Response
    {
        $agente = $this->meuAgente($req);
        if ($agente === null) {
            return Resposta::erro($res, 'Sua conta não é de agente de call center.', 403);
        }

        // Com tabulação pendente, "voltar" seria sair do pós-atendimento
        // sem dizer o que foi resolvido — que é o que a fila proíbe.
        if (self::pendentes((int) $agente['id']) !== []) {
            return Resposta::erro($res, 'Tabule os atendimentos pendentes antes de voltar a receber chamadas.', 409);
        }

        return $this->executar($res, function () use ($agente) {
            Cc::compartilhado()->despausar((int) $agente['id']);

            return ['ok' => true];
        });
    }

    /**
     * POST /api/cc/atendimentos/{id}/tabular {tabulacao_id, observacao}
     *
     * Tabulado o último pendente, o agente sai do "Pós-atendimento"
     * sozinho — pausa escolhida por ele fica como está.
     */
    public function tabular(Request $req, Response $res, array $args): Response
    {
        $agente = $this->meuAgente($req);
        if ($agente === null) {
            return Resposta::erro($res, 'Sua conta não é de agente de call center.', 403);
        }

        $atendimento = Bd::um('SELECT * FROM cc_atendimentos WHERE id = ? AND agente_id = ?',
                              [(int) $args['id'], (int) $agente['id']]);
        if ($atendimento === null) {
            return Resposta::erro($res, 'Atendimento não encontrado.', 404);
        }

        $c = (array) $req->getParsedBody();
        $tabulacao = (int) ($c['tabulacao_id'] ?? 0);
        $valida = Bd::valor(
            'SELECT t.id FROM cc_tabulacoes t LEFT JOIN filas f ON f.id = t.fila_id
              WHERE t.id = ? AND t.ativo = 1 AND (t.fila_id IS NULL OR f.numero = ?)',
            [$tabulacao, $atendimento['fila']]
        );
        if (!$valida) {
            return Resposta::erro($res, 'Escolha o que foi resolvido neste atendimento.', 422);
        }

        $obs = mb_substr(trim((string) ($c['observacao'] ?? '')), 0, 1000);
        Bd::executar(
            'UPDATE cc_atendimentos SET tabulacao_id = ?, observacao = ?, tabulado_em = NOW() WHERE id = ?',
            [$tabulacao, $obs === '' ? null : $obs, (int) $atendimento['id']]
        );

        $voltou = false;
        if (self::pendentes((int) $agente['id']) === []) {
            try {
                $cc = Cc::compartilhado();
                $estado = $cc->estado()['agentes'][(int) $agente['id']] ?? null;
                if ($estado !== null && $estado['pausado'] && $estado['motivo'] === Cc::PAUSA_TABULACAO) {
                    $cc->despausar((int) $agente['id']);
                    $voltou = true;
                }
            } catch (\Throwable) {
                // A tabulação está gravada; a volta o agente faz pelo botão.
            }
        }

        return Resposta::json($res, ['ok' => true, 'voltou' => $voltou]);
    }

    // ------------------------------------------------------------------
    // Supervisor
    // ------------------------------------------------------------------

    /** POST /api/cc/agentes/{id}/comando {acao: pausar|voltar|sair|escutar|sussurrar|intervir, motivo_id} */
    public function comando(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $agente = Cc::agente($id);
        if ($agente === null) {
            return Resposta::erro($res, 'Agente não encontrado.', 404);
        }

        $c = (array) $req->getParsedBody();
        $acao = (string) ($c['acao'] ?? '');
        $usuario = $req->getAttribute('usuario');

        $meuRamal = '';
        if (in_array($acao, ['escutar', 'sussurrar', 'intervir'], true)) {
            $meuRamal = preg_replace('/\D/', '', (string) ($usuario['ramal'] ?? '')) ?? '';
            if ($meuRamal === '') {
                return Resposta::erro($res, 'Sua conta não tem ramal. Vincule um ramal ao seu usuário para '
                    . 'escutar, sussurrar ou intervir: é ele que vai tocar.', 422);
            }
        }

        $resposta = $this->executar($res, function () use ($acao, $id, $c, $meuRamal) {
            $cc = Cc::compartilhado();

            return match ($acao) {
                'pausar' => (function () use ($cc, $id, $c) {
                    $motivo = self::motivo((int) ($c['motivo_id'] ?? 0), true)
                        ?? throw new \DomainException('Escolha um motivo de pausa.');
                    $cc->pausar($id, $motivo);

                    return ['motivo' => $motivo];
                })(),
                'voltar'  => (function () use ($cc, $id) { $cc->despausar($id); return ['ok' => true]; })(),
                'sair'    => ['filas' => $cc->deslogar($id)],
                'escutar', 'sussurrar', 'intervir'
                          => (function () use ($cc, $id, $acao, $meuRamal) {
                                 $cc->monitorar($id, (string) $meuRamal, $acao);

                                 return ['ramal' => $meuRamal];
                             })(),
                default   => throw new \DomainException('Comando desconhecido.'),
            };
        });

        if ($resposta->getStatusCode() < 300) {
            Auditoria::registrar($usuario, 'editar', 'cc.supervisor', "{$acao} agente {$agente['nome']}");
        }

        return $resposta;
    }

    // ------------------------------------------------------------------
    // Cadastro de agentes
    // ------------------------------------------------------------------

    /** GET /api/cc/agentes */
    public function listar(Request $req, Response $res): Response
    {
        $agentes = Bd::todos(
            'SELECT a.id, a.nome, a.usuario_id, a.matricula, a.ramal_padrao, a.ativo,
                    (a.pin_hash IS NOT NULL AND a.pin_hash <> \'\') AS tem_pin,
                    u.usuario, u.ramal AS ramal_usuario, p.nome AS perfil
               FROM cc_agentes a
          LEFT JOIN usuarios u ON u.id = a.usuario_id
          LEFT JOIN perfis p ON p.id = u.perfil_id
           ORDER BY a.nome'
        );

        $filas = [];
        foreach (Bd::todos(
            'SELECT af.agente_id, af.fila_id, af.penalidade, f.numero, f.nome
               FROM cc_agente_filas af JOIN filas f ON f.id = af.fila_id ORDER BY f.numero'
        ) as $f) {
            $filas[(int) $f['agente_id']][] = [
                'fila_id' => (int) $f['fila_id'], 'numero' => $f['numero'],
                'nome' => $f['nome'], 'penalidade' => (int) $f['penalidade'],
            ];
        }
        foreach ($agentes as &$a) {
            $a['filas'] = $filas[(int) $a['id']] ?? [];
            $a['tem_pin'] = (bool) $a['tem_pin'];
            $a['usuario_id'] = $a['usuario_id'] !== null ? (int) $a['usuario_id'] : null;
        }
        unset($a);

        return Resposta::json($res, [
            'dados'    => $agentes,
            'filas'    => Bd::todos('SELECT id, numero, nome FROM filas WHERE ativo = 1 AND callcenter = 1 ORDER BY numero'),
            'usuarios' => Bd::todos(
                'SELECT u.id, u.nome, u.usuario, u.ramal FROM usuarios u
                  WHERE u.status = \'ativo\' ORDER BY u.nome'
            ),
        ]);
    }

    /** POST /api/cc/agentes — PUT /api/cc/agentes/{id} */
    public function salvar(Request $req, Response $res, array $args): Response
    {
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $c = (array) $req->getParsedBody();
        $atual = $id !== null ? Bd::um('SELECT * FROM cc_agentes WHERE id = ?', [$id]) : null;
        if ($id !== null && $atual === null) {
            return Resposta::erro($res, 'Agente não encontrado.', 404);
        }

        // A conta do console é opcional: sem ela o agente só atende pelo
        // telefone ou softphone, entrando com matrícula e PIN.
        $usuarioId = (int) (array_key_exists('usuario_id', $c) ? $c['usuario_id'] : ($atual['usuario_id'] ?? 0)) ?: null;
        $usuario = $usuarioId !== null
            ? Bd::um("SELECT id, nome FROM usuarios WHERE id = ? AND status = 'ativo'", [$usuarioId]) : null;
        $nome = trim((string) ($c['nome'] ?? ($atual['nome'] ?? '')));
        if ($nome === '' && $usuario !== null) {
            $nome = (string) $usuario['nome'];
        }
        $matricula = preg_replace('/\D/', '', (string) ($c['matricula'] ?? ($atual['matricula'] ?? ''))) ?? '';
        $ramal = preg_replace('/\D/', '', (string) ($c['ramal_padrao'] ?? ($atual['ramal_padrao'] ?? ''))) ?? '';
        $pin = (string) ($c['pin'] ?? '');
        $ativo = array_key_exists('ativo', $c) ? (int) (bool) $c['ativo'] : (int) ($atual['ativo'] ?? 1);

        $erros = [];
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) {
            $erros['nome'] = 'O nome tem de 2 a 80 caracteres: é o que o supervisor e o relatório mostram.';
        }
        if ($usuarioId !== null && $usuario === null) {
            $erros['usuario_id'] = 'Esta conta do console não existe ou está desativada.';
        } elseif ($usuarioId !== null
            && Bd::valor('SELECT id FROM cc_agentes WHERE usuario_id = ? AND id <> ?', [$usuarioId, $id ?? 0])) {
            $erros['usuario_id'] = 'Esta conta já é de outro agente.';
        }
        if (strlen($matricula) < 2 || strlen($matricula) > 8) {
            $erros['matricula'] = 'A matrícula tem de 2 a 8 dígitos: é o que se digita no telefone.';
        } elseif (Bd::valor('SELECT id FROM cc_agentes WHERE matricula = ? AND id <> ?', [$matricula, $id ?? 0])) {
            $erros['matricula'] = 'Esta matrícula já é de outro agente.';
        }
        if ($pin !== '' && preg_match('/^\d{4,8}$/', $pin) !== 1) {
            $erros['pin'] = 'O PIN tem de 4 a 8 dígitos.';
        }
        if ($ramal !== '' && !Bd::valor('SELECT id FROM ramais WHERE numero = ?', [$ramal])) {
            $erros['ramal_padrao'] = "O ramal {$ramal} não existe.";
        }

        $filas = [];
        foreach ((array) ($c['filas'] ?? []) as $f) {
            $filaId = (int) ($f['fila_id'] ?? 0);
            if (!Bd::valor('SELECT id FROM filas WHERE id = ? AND callcenter = 1', [$filaId])) {
                $erros['filas'] = 'Só filas marcadas como call center recebem agentes daqui.';
                continue;
            }
            $filas[$filaId] = max(0, min(9, (int) ($f['penalidade'] ?? 0)));
        }

        if ($erros !== []) {
            return Resposta::erro($res, 'Confira os campos destacados.', 422, ['campos' => $erros]);
        }

        $pdo = Bd::conexao();
        $pdo->beginTransaction();
        try {
            if ($id === null) {
                Bd::executar(
                    'INSERT INTO cc_agentes (nome, usuario_id, matricula, ramal_padrao, ativo) VALUES (?, ?, ?, ?, ?)',
                    [$nome, $usuarioId, $matricula, $ramal ?: null, $ativo]
                );
                $id = (int) $pdo->lastInsertId();
            } else {
                Bd::executar(
                    'UPDATE cc_agentes SET nome = ?, usuario_id = ?, matricula = ?, ramal_padrao = ?, ativo = ? WHERE id = ?',
                    [$nome, $usuarioId, $matricula, $ramal ?: null, $ativo, $id]
                );
            }

            if ($pin !== '') {
                $novo = Cc::novoPin($pin);
                Bd::executar('UPDATE cc_agentes SET pin_sal = ?, pin_hash = ? WHERE id = ?',
                             [$novo['sal'], $novo['hash'], $id]);
            } elseif (!empty($c['remover_pin'])) {
                Bd::executar('UPDATE cc_agentes SET pin_sal = NULL, pin_hash = NULL WHERE id = ?', [$id]);
            }

            if (array_key_exists('filas', $c)) {
                Bd::executar('DELETE FROM cc_agente_filas WHERE agente_id = ?', [$id]);
                foreach ($filas as $filaId => $penalidade) {
                    Bd::executar('INSERT INTO cc_agente_filas (agente_id, fila_id, penalidade) VALUES (?, ?, ?)',
                                 [$id, $filaId, $penalidade]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Quem está logado sente a mudança agora, não no próximo login:
        // fila nova entra, fila tirada sai, nível novo vale já.
        $aviso = null;
        try {
            $cc = Cc::compartilhado();
            if ($ativo === 0) {
                $cc->deslogar($id);
            } else {
                $cc->sincronizar($id);
            }
        } catch (\Throwable) {
            $aviso = 'Salvo. O Asterisk não respondeu, então quem está logado só vê a mudança no próximo login.';
        }

        Auditoria::registrar($req->getAttribute('usuario'), $atual === null ? 'criar' : 'editar',
                             'cc.agentes', "agente {$matricula}");

        return Resposta::json($res, ['id' => $id, 'aviso' => $aviso], $atual === null ? 201 : 200);
    }

    /** DELETE /api/cc/agentes/{id} */
    public function remover(Request $req, Response $res, array $args): Response
    {
        $id = (int) $args['id'];
        $agente = Cc::agente($id);
        if ($agente === null) {
            return Resposta::erro($res, 'Agente não encontrado.', 404);
        }

        try {
            Cc::compartilhado()->deslogar($id);
        } catch (\Throwable) {
            // Fora do Asterisk, o membro "Agente/<id>" sai no próximo reinício.
        }

        // O histórico fica: o queue_log guarda "Agente/<id>", e os
        // atendimentos ficam sem agente (ON DELETE SET NULL).
        Bd::executar('DELETE FROM cc_agentes WHERE id = ?', [$id]);
        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'cc.agentes', "agente {$agente['matricula']}");

        return Resposta::json($res, ['ok' => true]);
    }

    // ------------------------------------------------------------------
    // Retornos
    // ------------------------------------------------------------------

    /** GET /api/cc/retornos */
    public function retornos(Request $req, Response $res): Response
    {
        $estado = (string) ($req->getQueryParams()['estado'] ?? '');
        $sql = 'SELECT r.*, f.nome AS fila_nome FROM cc_retornos r LEFT JOIN filas f ON f.numero = r.fila';
        $params = [];
        if (in_array($estado, ['pendente', 'discando', 'concluido', 'falhou', 'cancelado'], true)) {
            $sql .= ' WHERE r.estado = ?';
            $params[] = $estado;
        } else {
            $sql .= ' WHERE r.pedido_em >= NOW() - INTERVAL 7 DAY';
        }

        return Resposta::json($res, ['dados' => Bd::todos($sql . ' ORDER BY r.pedido_em DESC LIMIT 300', $params)]);
    }

    /** POST /api/cc/retornos/{id} {acao: cancelar|refazer} */
    public function retorno(Request $req, Response $res, array $args): Response
    {
        $acao = (string) (((array) $req->getParsedBody())['acao'] ?? '');
        $n = match ($acao) {
            'cancelar' => Bd::executar(
                "UPDATE cc_retornos SET estado = 'cancelado', concluido_em = NOW() WHERE id = ? AND estado IN ('pendente','falhou')",
                [(int) $args['id']]
            ),
            'refazer' => Bd::executar(
                "UPDATE cc_retornos SET estado = 'pendente', tentativas = 0, proxima_em = NULL, resultado = NULL
                  WHERE id = ? AND estado IN ('falhou','cancelado','concluido')",
                [(int) $args['id']]
            ),
            default => -1,
        };

        if ($n === -1) {
            return Resposta::erro($res, 'Ação desconhecida.', 422);
        }
        if ($n === 0) {
            return Resposta::erro($res, 'Este retorno não está num estado em que isso se aplique.', 409);
        }

        return Resposta::json($res, ['ok' => true]);
    }

    // ------------------------------------------------------------------
    // Configuração do módulo: os códigos do telefone
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private static function codigosDoTelefone(): array
    {
        $marcas = implode(',', array_fill(0, count(self::CODIGOS), '?'));

        return Bd::todos(
            "SELECT id, chave, nome, codigo, argumento, descricao, ativo FROM codigos_recurso
              WHERE chave IN ({$marcas}) ORDER BY ordem",
            self::CODIGOS
        );
    }

    /** GET /api/cc/config */
    public function config(Request $req, Response $res): Response
    {
        return Resposta::json($res, ['codigos' => self::codigosDoTelefone()]);
    }

    /**
     * PUT /api/cc/config {codigos: [{chave, codigo, ativo}]}
     *
     * O código precisa ser discável e não pode colidir com outro código
     * de recurso nem com o de uma pausa — nem ser o começo de um, nem
     * começar com um (CodigosDiscagem).
     */
    public function salvarConfig(Request $req, Response $res): Response
    {
        $pedidos = [];
        foreach ((array) (((array) $req->getParsedBody())['codigos'] ?? []) as $p) {
            $chave = (string) ($p['chave'] ?? '');
            if (in_array($chave, self::CODIGOS, true)) {
                $pedidos[$chave] = [
                    'codigo' => trim((string) ($p['codigo'] ?? '')),
                    'ativo'  => (int) (bool) ($p['ativo'] ?? 1),
                ];
            }
        }

        $erros = [];
        $novos = [];
        foreach ($pedidos as $chave => $p) {
            if (preg_match('/^[*#]?[0-9]{1,6}$/', $p['codigo']) !== 1) {
                $erros[$chave] = 'Use * seguido de 1 a 6 dígitos (como *11).';
                continue;
            }
            $novos[$chave] = $p['codigo'];
        }

        // Um contra o outro, dentro do pedido, e contra o resto: os outros
        // códigos de recurso e o código de cada pausa. Os do pedido entram
        // com o código novo, não com o que está no banco.
        $nomes = array_column(Bd::todos(
            'SELECT chave, nome FROM codigos_recurso WHERE chave IN (' . implode(',', array_fill(0, count(self::CODIGOS), '?')) . ')',
            self::CODIGOS
        ), 'nome', 'chave');
        $outros = CodigosDiscagem::outros('recurso', null, array_keys($novos));
        foreach ($novos as $chave => $codigo) {
            if ($pedidos[$chave]['ativo'] !== 1) {
                continue;
            }
            $colisao = CodigosDiscagem::colisao($codigo, 'recurso', null, $outros);
            if ($colisao !== null) {
                $erros[$chave] = $colisao;
            }
            $outros[] = ['codigo' => $codigo, 'nome' => (string) ($nomes[$chave] ?? $chave)];
        }

        if ($erros !== []) {
            return Resposta::erro($res, 'Há códigos que colidem ou não são discáveis.', 422, ['campos' => $erros]);
        }

        foreach ($pedidos as $chave => $p) {
            Bd::executar('UPDATE codigos_recurso SET codigo = ?, ativo = ? WHERE chave = ?',
                         [$p['codigo'], $p['ativo'], $chave]);
        }
        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'cc.config', 'códigos do telefone');

        return Resposta::json($res, ['codigos' => self::codigosDoTelefone(), 'config_pendente' => true]);
    }

    // ------------------------------------------------------------------
    // Relatório
    // ------------------------------------------------------------------

    /**
     * GET /api/cc/relatorio?de=Y-m-d&ate=Y-m-d&fila=&formato=csv&tabela=agentes|filas|horas
     */
    public function relatorio(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $hoje = date('Y-m-d');
        $r = RelatorioFilas::doPeriodo(
            (string) ($q['de'] ?? $hoje),
            (string) ($q['ate'] ?? $hoje),
            preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($q['fila'] ?? '')) ?: null,
            !empty($q['so_callcenter'])
        );

        if (($q['formato'] ?? '') === 'csv') {
            $tabela = in_array($q['tabela'] ?? '', ['agentes', 'filas', 'horas'], true) ? $q['tabela'] : 'agentes';

            return self::csv($res, $tabela, $r);
        }

        return Resposta::json($res, [
            'filas'      => $r->filas(),
            'agentes'    => $r->agentes(),
            'por_hora'   => $r->porHora(),
            'por_dia'    => $r->porDia(),
            'tabulacoes' => $r->tabulacoes(),
            'lista_filas' => Bd::todos('SELECT numero, nome, callcenter FROM filas WHERE ativo = 1 ORDER BY numero'),
        ]);
    }

    /** A planilha: separador ";" e BOM, que é como o Excel em português abre sem estragar acento. */
    private static function csv(Response $res, string $tabela, RelatorioFilas $r): Response
    {
        [$cabecalho, $linhas] = match ($tabela) {
            'filas' => [
                ['Fila', 'Nome', 'Recebidas', 'Atendidas', 'No SLA', 'SLA %', 'Abandonadas', 'Abandono %',
                 'Estouradas', 'Sem agente', 'Pediram retorno', 'TME (s)', 'Maior espera (s)', 'TMA (s)'],
                array_map(static fn ($f) => [$f['numero'], $f['nome'], $f['recebidas'], $f['atendidas'], $f['no_sla'],
                    $f['sla'], $f['abandonadas'], $f['abandono'], $f['estouradas'], $f['sem_agente'], $f['retorno'],
                    $f['tme'], $f['maior_espera'], $f['tma']], $r->filas()),
            ],
            'horas' => [
                ['Hora', 'Recebidas', 'Atendidas', 'Abandonadas'],
                array_map(static fn ($h) => [sprintf('%02d:00', $h['hora']), $h['recebidas'], $h['atendidas'], $h['abandonadas']],
                          $r->porHora()),
            ],
            default => [
                ['Agente', 'Matrícula', 'Atendidas', 'TMA (s)', 'Falado (s)', 'Não atendeu', 'Logado (s)',
                 'Em pausa (s)', 'Pausa produtiva (s)', 'Ocupação %', 'Satisfação (0-100)', 'Respostas da pesquisa', 'Tabuladas'],
                array_map(static fn ($a) => [$a['nome'], $a['matricula'], $a['atendidas'], $a['tma'], $a['falado'],
                    $a['nao_atendeu'], $a['logado'], $a['pausado'], $a['pausa_produtiva'], $a['ocupacao'], $a['nota'],
                    $a['respostas'], $a['tabuladas']], $r->agentes()),
            ],
        };

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $cabecalho, ';', '"', '');
        // Um nome de agente ou de fila que comece com = + - @ vira fórmula
        // no Excel de quem abrir a planilha. O apóstrofo na frente faz a
        // célula ser lida como texto.
        $celula = static function ($v) {
            if (is_float($v)) {
                return str_replace('.', ',', (string) $v);
            }
            if (is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0])) {
                return "'" . $v;
            }

            return $v;
        };
        foreach ($linhas as $l) {
            fputcsv($fh, array_map($celula, $l), ';', '"', '');
        }
        rewind($fh);
        $conteudo = (string) stream_get_contents($fh);
        fclose($fh);

        $res->getBody()->write($conteudo);

        return $res->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"callcenter-{$tabela}.csv\"")
            ->withHeader('Cache-Control', 'no-store');
    }

    // ------------------------------------------------------------------

    private function meuAgente(Request $req): ?array
    {
        $a = Cc::agenteDoUsuario((int) $req->getAttribute('usuario')['id']);

        return $a !== null && (int) $a['ativo'] === 1 ? $a : null;
    }

    /** O nome do motivo, se ele existe, está ativo e pode ser escolhido. */
    private static function motivo(int $id, bool $aceitaSistema): ?string
    {
        $nome = Bd::valor(
            'SELECT nome FROM cc_pausas_motivos WHERE id = ? AND ativo = 1' . ($aceitaSistema ? '' : ' AND sistema = 0'),
            [$id]
        );

        return $nome ? (string) $nome : null;
    }

    /** Roda um comando do call center e traduz o erro para quem está na tela. */
    private function executar(Response $res, callable $f): Response
    {
        try {
            return Resposta::json($res, $f());
        } catch (\DomainException $e) {
            return Resposta::erro($res, $e->getMessage(), 422);
        } catch (\PDOException) {
            // A mensagem do banco carrega SQL e nomes internos: não é para a tela.
            return Resposta::erro($res, 'Falha ao consultar o banco. Tente de novo em instantes.', 503);
        } catch (\Throwable $e) {
            return Resposta::erro($res, 'O Asterisk não respondeu: ' . $e->getMessage(), 503);
        }
    }
}
