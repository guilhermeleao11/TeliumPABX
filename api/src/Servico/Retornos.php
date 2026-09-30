<?php
declare(strict_types=1);

namespace Telium\Servico;

use Telium\Suporte\Ami;
use Telium\Suporte\Bd;

/**
 * Retorno (callback): o cliente pediu na espera para ser chamado de volta.
 *
 * O retorno é agente primeiro. A central só disca para o cliente depois
 * que um agente atendeu — ligar para o cliente e deixá-lo esperando de
 * novo na fila seria pior do que não ter oferecido nada.
 *
 * O caminho, montado no dialplan (extensions.callcenter.conf):
 *
 *   Originate  Local/<id>@telium-cc-retorno   → entra na fila como se
 *              fosse o cliente, com prioridade, e toca para um agente;
 *   ao atender → telium-cc-retorno-cliente     → disca o número do
 *              cliente pela rota de saída e grava o resultado.
 *
 * Um retorno por fila de cada vez, e só quando há agente livre nela:
 * ocupar os agentes com retornos deixaria sem atendimento quem está
 * ligando agora.
 */
final class Retornos
{
    /** Quanto um retorno pode ficar "discando" antes de voltar à fila. */
    private const PRESO_MINUTOS = 5;

    public function __construct(private readonly Ami $ami)
    {
    }

    /** @param array{filas: array<string,mixed>, agentes: array<int,mixed>} $estado */
    public function despachar(array $estado): int
    {
        // Um Originate que nunca voltou (central reiniciada no meio, por
        // exemplo) deixaria o retorno preso para sempre.
        //
        // O limite de tentativas vale aqui também: sem ele, um retorno cuja
        // chamada caía sem avisar voltava à fila para sempre. Um retorno em
        // conversa não entra nesta conta: ao ser atendido pelo agente, o
        // dialplan empurra o proxima_em para longe (TELIUM_CC_RETORNO_ATENDEU).
        // LEFT JOIN: um retorno cuja fila foi apagada ou renumerada também
        // sai do "discando" — com JOIN ele ficava preso ali para sempre.
        Bd::executar(
            "UPDATE cc_retornos r LEFT JOIN filas f ON f.numero = r.fila
                SET r.estado = IF(r.tentativas >= COALESCE(f.retorno_tentativas, 0), 'falhou', 'pendente'),
                    r.resultado = 'sem resposta da central',
                    r.concluido_em = IF(r.tentativas >= COALESCE(f.retorno_tentativas, 0), NOW(), NULL)
              WHERE r.estado = 'discando' AND r.proxima_em < NOW() - INTERVAL ? MINUTE",
            [self::PRESO_MINUTOS]
        );

        $pendentes = Bd::todos(
            "SELECT r.* FROM cc_retornos r
               JOIN filas f ON f.numero = r.fila AND f.ativo = 1 AND f.callcenter = 1
              WHERE r.estado = 'pendente' AND (r.proxima_em IS NULL OR r.proxima_em <= NOW())
                -- Um retorno por fila discando de cada vez. O que já está em
                -- conversa (proxima_em empurrado para longe pelo ATENDEU) não
                -- conta: se a central caísse no meio dele, a fila ficava seis
                -- horas sem retorno nenhum.
                AND NOT EXISTS (SELECT 1 FROM cc_retornos d WHERE d.fila = r.fila AND d.estado = 'discando'
                                   AND d.proxima_em < NOW() + INTERVAL 1 HOUR)
           ORDER BY r.pedido_em
              LIMIT 20"
        );

        $disparados = 0;
        $filasUsadas = [];

        foreach ($pendentes as $r) {
            $fila = (string) $r['fila'];
            if (isset($filasUsadas[$fila]) || !self::temAgenteLivre($estado, $fila)) {
                continue;
            }

            // Marca antes de discar, numa condição só: dois despachos ao
            // mesmo tempo não ligam duas vezes para o mesmo cliente.
            $pegou = Bd::executar(
                "UPDATE cc_retornos SET estado = 'discando', tentativas = tentativas + 1, proxima_em = NOW()
                  WHERE id = ? AND estado = 'pendente'",
                [(int) $r['id']]
            );
            if ($pegou !== 1) {
                continue;
            }

            $resposta = $this->ami->acao([
                'Action'   => 'Originate',
                'Channel'  => "Local/{$r['id']}@telium-cc-retorno/n",
                'Context'  => 'telium-cc-retorno-cliente',
                'Exten'    => (string) $r['numero'],
                'Priority' => '1',
                // O agente vê o número do cliente, não "Local/…".
                'CallerID' => sprintf('"Retorno %s" <%s>', substr((string) ($r['nome'] ?: ''), 0, 30), $r['numero']),
                'Variable' => "TELIUM_RETORNO={$r['id']},TELIUM_RETORNO_FILA={$fila}",
                // Maior que a espera na fila (120 s): se o Originate desistisse
                // antes, as duas pontas caíam sem passar pelo registro do
                // resultado, e o retorno ficava preso em "discando".
                'Timeout'  => '135000',
                'Async'    => 'true',
            ]);

            if (!str_contains($resposta, 'Success')) {
                Bd::executar(
                    "UPDATE cc_retornos SET estado = 'pendente', proxima_em = NOW() + INTERVAL 1 MINUTE,
                            resultado = 'a central recusou a discagem' WHERE id = ?",
                    [(int) $r['id']]
                );
                continue;
            }

            $filasUsadas[$fila] = true;
            $disparados++;
        }

        return $disparados;
    }

    /** Alguém logado na fila, sem pausa, livre e fora de chamada. */
    public static function temAgenteLivre(array $estado, string $fila): bool
    {
        foreach ($estado['agentes'] as $a) {
            if (isset($a['filas'][$fila]) && !$a['pausado'] && !$a['em_chamada'] && $a['status'] === 'livre') {
                return true;
            }
        }

        return false;
    }
}
