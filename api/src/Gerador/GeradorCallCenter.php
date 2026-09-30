<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Bd;

/**
 * Dialplan do call center: extensions.callcenter.conf.
 *
 * Três coisas moram aqui:
 *
 *  - telium-cc-telefone: o que os códigos *40, *42, *44 e *49 fazem,
 *    com os áudios do call center (sounds/telium-cc).
 *    A decisão não é do dialplan: ele pergunta ao serviço de tempo real
 *    (CURL em 127.0.0.1:8095), que usa a mesma classe que o console. Um
 *    caminho só para entrar, pausar e sair — e as mesmas regras.
 *
 *  - telium-cc-retorno-<fila>: para onde a fila manda o cliente que
 *    apertou a tecla de retorno na espera.
 *
 *  - telium-cc-retorno e telium-cc-retorno-cliente: o retorno em si,
 *    agente primeiro, disparado pelo serviço.
 */
final class GeradorCallCenter
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Contexto: call center')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco();

        $this->telefone($b);
        $this->retornoNaEspera($b);
        $this->retorno($b);

        return ['extensions.callcenter.conf' => $b->texto()];
    }

    /** O segredo que o serviço confere em /interno/…. */
    public static function segredo(): string
    {
        return (string) (Bd::valor("SELECT valor FROM sistema WHERE chave = 'cc_segredo_interno'") ?: '');
    }

    private function telefone(Bloco $b): void
    {
        $url = 'http://127.0.0.1:8095/interno';
        $k = self::segredo();
        // O ramal de quem discou. TELIUM_RAMAL vem do endpoint; o número
        // do CallerID é a reserva — ele pode ter sido trocado pelo CID do ramal.
        $eu = '${IF($["${TELIUM_RAMAL}" != ""]?${TELIUM_RAMAL}:${CALLERID(num)})}';

        $b->comentario('Códigos de agente. Chamados por GoSub a partir de telium-recursos.')
          ->comentario('A resposta do serviço é uma palavra: ok, pin, ocupado, semfila,')
          ->comentario('naologado, motivo, fora ou erro.')
          ->contexto('telium-cc-telefone');

        // Os áudios são os do call center (sounds/telium-cc), em português.
        // O código é a matrícula, ou matrícula*PIN para quem tem PIN.
        $b->exten('login', 'Answer()')
          ->same('Wait(1)')
          ->same('Read(TELIUM_CC_COD,telium-cc/digite-codigo-agente,20,,2,8)')
          ->same('GotoIf($["${TELIUM_CC_COD}" = ""]?vazio)')
          ->same("GoSub(telium-cc-pedido,s,1({$url}/login?k={$k}&ramal={$eu}&codigo=\${URIENCODE(\${TELIUM_CC_COD})},telium-cc/login-realizado))")
          ->same('Return()')
          ->same('Playback(telium-cc/agente-invalido)', 'vazio')
          ->same('Return()');

        $b->exten('logout', 'Answer()')
          ->same('Wait(1)')
          ->same("GoSub(telium-cc-pedido,s,1({$url}/logout?k={$k}&ramal={$eu},telium-cc/logout-realizado))")
          ->same('Return()');

        $b->exten('pausa', 'Answer()')
          ->same('Wait(1)')
          ->same("GoSub(telium-cc-pedido,s,1({$url}/pausa?k={$k}&ramal={$eu}&motivo=\${FILTER(0-9,\${ARG1})},telium-cc/pausa-ativada))")
          ->same('Return()');

        $b->exten('volta', 'Answer()')
          ->same('Wait(1)')
          ->same("GoSub(telium-cc-pedido,s,1({$url}/volta?k={$k}&ramal={$eu},telium-cc/pausa-removida))")
          ->same('Return()');

        // Resposta vazia é o CURL sem resposta: o serviço está fora.
        $b->branco()
          ->comentario('ARG1 = endereço do pedido, ARG2 = áudio de sucesso')
          ->contexto('telium-cc-pedido')
          ->exten('s', 'Set(TELIUM_CC_R=${CURL(${ARG1})})')
          ->same('NoOp(Call center respondeu: ${TELIUM_CC_R})')
          ->same('GotoIf($["${TELIUM_CC_R}" = "ok"]?ok)')
          ->same('GotoIf($["${TELIUM_CC_R}" = "pin"]?invalido)')
          ->same('GotoIf($["${TELIUM_CC_R}" = "semfila"]?semfila)')
          ->same('GotoIf($["${TELIUM_CC_R}" = "" | "${TELIUM_CC_R}" = "fora"]?fora)')
          ->same('Playback(telium-cc/operacao-falhou)')
          ->same('Return()')
          ->same('Playback(${ARG2})', 'ok')
          ->same('Return()')
          ->same('Playback(telium-cc/agente-invalido)', 'invalido')
          ->same('Return()')
          ->same('Playback(telium-cc/agente-sem-fila)', 'semfila')
          ->same('Return()')
          ->same('Playback(telium-cc/servico-indisponivel)', 'fora')
          ->same('Return()');
    }

    /** O cliente apertou a tecla de retorno enquanto esperava. */
    private function retornoNaEspera(Bloco $b): void
    {
        $filas = Bd::todos(
            "SELECT f.numero, f.nome, f.retorno_tecla, s.arquivo AS anuncio
               FROM filas f
          LEFT JOIN anuncios a ON a.id = f.retorno_anuncio_id AND a.ativo = 1
          LEFT JOIN audios s ON s.id = a.audio_id
              WHERE f.ativo = 1 AND f.callcenter = 1
                AND f.retorno_tecla IS NOT NULL AND f.retorno_tecla <> ''
           ORDER BY f.numero"
        );

        foreach ($filas as $f) {
            $tecla = preg_replace('/[^0-9*#]/', '', (string) $f['retorno_tecla']) ?? '';
            if ($tecla === '') {
                continue;
            }
            $numero = (string) $f['numero'];

            $b->branco()
              ->comentario("Retorno pedido na espera da fila {$numero} — {$f['nome']}")
              ->contexto("telium-cc-retorno-{$numero}")
              ->exten($tecla, "NoOp(Pedido de retorno na fila {$numero}: \${CALLERID(num)})")
              // Sem número não há para onde retornar: o cliente volta
              // para a fila, no fim dela, em vez de ficar sem nada.
              ->same('GotoIf($["${FILTER(0-9,${CALLERID(num)})}" = ""]?sem-numero)')
              // O nome vai inteiro em VALUE (e não VAL1): o func_odbc parte o
              // valor nas vírgulas, e "Silva, João" virava só "Silva".
              ->same("Set(ODBC_TELIUM_CC_RETORNO({$numero},\${FILTER(0-9,\${CALLERID(num)})},\${UNIQUEID})=\${CALLERID(name)})")
              ->same('Playback(' . (Som::prompt($f['anuncio'] ?? null) ?: 'auth-thankyou') . ')')
              ->same('Hangup()')
              ->same('Playback(invalid)', 'sem-numero')
              ->same("Goto(telium-filas,{$numero},1)");
        }
    }

    /**
     * O retorno disparado pelo serviço (Retornos::despachar).
     *
     * O lado ;2 do Local entra na fila no lugar do cliente, com
     * prioridade. Quando um agente atende, o lado ;1 cai em
     * telium-cc-retorno-cliente e disca o cliente pela rota de saída.
     */
    private function retorno(Bloco $b): void
    {
        $cid = preg_replace('/\D/', '', (string) (Bd::valor('SELECT telefone FROM empresa WHERE id = 1') ?: '')) ?? '';

        $b->branco()
          ->comentario('Retorno: o agente primeiro, depois o cliente')
          ->contexto('telium-cc-retorno')
          // "_X." pede pelo menos dois dígitos: o retorno de id 1 a 9
          // não casaria com nada. "_X!" é um dígito ou mais.
          ->exten('_X!', 'NoOp(Retorno ${EXTEN})')
          ->same('Set(TELIUM_RETORNO_ID=${EXTEN})')
          ->same('Set(TELIUM_RETORNO_FILA=${ODBC_TELIUM_CC_RETORNO_FILA(${EXTEN})})')
          ->same('GotoIf($["${TELIUM_RETORNO_FILA}" = ""]?fim)')
          ->same('Set(CDR(fila)=${TELIUM_RETORNO_FILA})')
          ->same('Set(__TELIUM_FILA=${TELIUM_RETORNO_FILA})')
          ->same('Set(QUEUE_PRIO=10)')
          ->same('Queue(${TELIUM_RETORNO_FILA},t,,,120)')
          // Voltou da fila sem agente: não é tentativa gasta com o cliente.
          ->same('Set(ODBC_TELIUM_CC_RETORNO_FIM(${EXTEN},SEMAGENTE)=x)', 'fim')
          ->same('Hangup()')
          // Caiu sem voltar ao dialplan (o Originate desistiu, a central
          // derrubou): o resultado é gravado mesmo assim. Com agente — a
          // variável MEMBERINTERFACE existe — quem grava é o lado do cliente.
          ->exten('h', 'ExecIf($["${MEMBERINTERFACE}" = "" & "${TELIUM_RETORNO_ID}" != ""]'
              . '?Set(ODBC_TELIUM_CC_RETORNO_FIM(${TELIUM_RETORNO_ID},SEMAGENTE)=x))');

        $b->branco()
          ->comentario('O agente atendeu: agora o cliente')
          ->contexto('telium-cc-retorno-cliente')
          ->exten('_X!', 'NoOp(Retorno ${TELIUM_RETORNO}: discando ${EXTEN})')
          // O agente atendeu: a conversa pode passar dos cinco minutos, e o
          // varredor dos retornos presos não pode religar para o cliente.
          ->same('Set(ODBC_TELIUM_CC_RETORNO_ATENDEU(${TELIUM_RETORNO})=x)')
          ->same('Set(CDR(direcao)=saida)');

        // O cliente vê o número da empresa, se houver um cadastrado — e
        // não o próprio número, que é o que estava no CallerID.
        if ($cid !== '') {
            $b->same("Set(CALLERID(all)=\"\" <{$cid}>)");
        }

        // O número vem como a operadora entregou (11999990000), e a rota
        // costuma esperar o prefixo de saída (011999990000). Sem rota para
        // o número cru, tenta com o 0 na frente — senão todo retorno de
        // DDD ou celular falhava sem sair da central.
        $b->same('Set(TELIUM_NUM=${EXTEN})')
          ->same('ExecIf($[!${DIALPLAN_EXISTS(telium-saida,${TELIUM_NUM},1)}'
               . ' & ${DIALPLAN_EXISTS(telium-saida,0${TELIUM_NUM},1)}]?Set(TELIUM_NUM=0${TELIUM_NUM}))')
          ->same('Dial(Local/${TELIUM_NUM}@telium-saida/n,60)')
          ->same('Hangup()')
          ->exten('h', 'Set(ODBC_TELIUM_CC_RETORNO_FIM(${TELIUM_RETORNO},${IF($["${DIALSTATUS}" = ""]?FALHOU:${DIALSTATUS})})=x)');
    }
}
