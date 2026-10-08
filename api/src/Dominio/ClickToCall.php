<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Http\Controllers\Diagnostico;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;

/**
 * Click to call: a central toca o ramal e, quando ele atende, disca o número.
 *
 * Três portas usam esta classe: o "discar" do Meu Ramal (sessão do
 * console), a API para outros sistemas (chave de API, em Integrações) e
 * o teste da tela de Integrações. A chamada é a mesma nas três.
 *
 * O destino entra pelo contexto do próprio ramal, no canal do próprio
 * ramal: passa pela permissão de discagem, pelo limite de chamadas e
 * pela rota de saída como se a pessoa tivesse discado no aparelho. Uma
 * chave de API não alcança o que o ramal não alcançaria.
 */
final class ClickToCall
{
    /** O começo de toda chave, para quem a achar num log saber o que é. */
    private const PREFIXO = 'tlm_c2c_';

    /**
     * Uma chave nova. O texto só existe aqui: o banco guarda o hash e os
     * quatro últimos caracteres, para a tela mostrar qual é qual.
     *
     * @return array{token:string, hash:string, fim:string}
     */
    public static function novaChave(): array
    {
        $token = self::PREFIXO . bin2hex(random_bytes(24));

        return ['token' => $token, 'hash' => hash('sha256', $token), 'fim' => substr($token, -4)];
    }

    /** @return array<string,mixed>|null a chave ativa dona do texto */
    public static function chaveDoToken(string $token): ?array
    {
        $token = trim($token);
        if (!str_starts_with($token, self::PREFIXO)) {
            return null;
        }

        return Bd::um('SELECT * FROM click2call_chaves WHERE token_hash = ? AND ativo = 1', [hash('sha256', $token)]);
    }

    /**
     * Uma lista de "1001, 1002 2000" (vírgula, espaço ou quebra de linha).
     *
     * @return list<string>
     */
    public static function lista(?string $texto): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', (string) $texto) ?: []),
            static fn (string $x): bool => $x !== ''
        ));
    }

    /** O IP está numa das faixas (192.168.0.0/24, 200.1.2.3)? Lista vazia: qualquer um. */
    public static function ipPermitido(string $ip, ?string $faixas): bool
    {
        $lista = self::lista($faixas);
        if ($lista === []) {
            return true;
        }
        foreach ($lista as $faixa) {
            if (Provisionamento::dentroDaFaixa($ip, $faixa)) {
                return true;
            }
        }

        return false;
    }

    /** Só o que se disca num teclado: o valor viaja para uma ação do AMI. */
    public static function numeroLimpo(string $numero): string
    {
        return preg_replace('/[^0-9*#+]/', '', $numero) ?? '';
    }

    /**
     * Toca o ramal e, atendido, disca o destino.
     *
     * @param array<string,mixed> $ramal linha de ramais
     * @param string $nome o que o visor do ramal mostra (o nome do cliente, vindo do CRM)
     * @param bool   $atenderSozinho pede ao telefone que atenda em viva-voz
     * @return array{uniqueid:string, ramal:string, destino:string}
     */
    public static function originar(array $ramal, string $destino, string $nome = '', bool $atenderSozinho = false): array
    {
        $destino = self::numeroLimpo($destino);
        if ($destino === '') {
            throw new \InvalidArgumentException('Informe o número a discar.');
        }

        $numero = (string) $ramal['numero'];
        $contexto = (string) ($ramal['contexto_custom'] ?: $ramal['contexto']);

        // Conferir ANTES de originar. Com Async o AMI responde "Success"
        // no instante em que aceita o pedido, mesmo quando o canal não
        // pode ser criado — e quem pediu ouvia "o ramal vai tocar" para
        // um telefone que não estava registrado.
        $contatos = Ami::tentarComando('pjsip show contacts');
        if ($contatos !== null) {
            $alcancavel = Diagnostico::contatoAlcancavel($contatos, $numero);
            if ($alcancavel === null) {
                throw new \DomainException(
                    "O ramal {$numero} não está registrado na central agora. "
                    . 'Confira se o aparelho está ligado e conectado à rede.'
                );
            }
            if ($alcancavel === false) {
                throw new \DomainException(
                    "A central não consegue falar com o ramal {$numero}: ele está registrado, mas não "
                    . 'responde. Costuma ser o aparelho fora da rede ou um firewall no caminho.'
                );
            }
        }

        // O nome vai entre aspas no CallerID: aspas e sinais de maior e
        // menor dentro dele quebrariam o campo.
        $nome = trim(preg_replace('/["<>\r\n\\\\]/', '', $nome) ?? '');
        $nome = mb_substr($nome !== '' ? $nome : $destino, 0, 40);

        // O id da chamada é dado por nós: é o uniqueid do CDR, e o sistema
        // que pediu pode guardá-lo para achar a gravação e o registro depois.
        $uniqueid = 'c2c-' . bin2hex(random_bytes(8));

        $campos = [
            'Action'    => 'Originate',
            'Channel'   => "PJSIP/{$numero}",
            'Context'   => $contexto,
            'Exten'     => $destino,
            'Priority'  => '1',
            // Quem vê o visor é o ramal, e o que ele precisa ver é para
            // onde a chamada vai.
            'CallerID'  => "\"{$nome}\" <{$destino}>",
            'Timeout'   => '30000',
            'ChannelId' => $uniqueid,
            'Async'     => 'true',
        ];
        if ($atenderSozinho) {
            // Os mesmos cabeçalhos da interfonia (sub-autoatende): Call-Info
            // para Yealink, Grandstream, Fanvil e Polycom; Alert-Info para
            // Intelbras e modelos antigos. Sem a barra antes do ";": ela só
            // existe no dialplan, onde o ";" começaria um comentário.
            $campos['Variable'] = [
                'PJSIP_HEADER(add,Call-Info)=<uri>;answer-after=0',
                'PJSIP_HEADER(add,Alert-Info)=Auto Answer',
            ];
        }

        // doAmbiente(), não new Ami(): o construtor nu usa credenciais
        // vazias e o AMI recusa a autenticação.
        $ami = Ami::doAmbiente();
        $ami->conectar();
        try {
            $resposta = $ami->acao($campos);
        } finally {
            $ami->desconectar();
        }

        if (!str_contains($resposta, 'Success')) {
            throw new \RuntimeException('A central recusou a chamada. Confira se o ramal está registrado.');
        }

        return ['uniqueid' => $uniqueid, 'ramal' => $numero, 'destino' => $destino];
    }
}
