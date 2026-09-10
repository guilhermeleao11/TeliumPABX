<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Dominio\Permissoes;
use Telium\Suporte\Ami;
use Telium\Suporte\Resposta;

/**
 * Console do Asterisk pela web.
 *
 * Rodar comando arbitrário num PABX é poderoso, então há três camadas:
 * caracteres de escape para o shell são recusados sempre; comandos de
 * leitura passam com a permissão do módulo; qualquer coisa que altere
 * estado exige a ação "reiniciar".
 */
final class Cli
{
    /** Sequências que permitiriam sair do CLI para o shell. */
    private const PROIBIDO = ['!', ';', '|', '`', '$(', '&&', '>', '<', "\n", "\r"];

    /** Comandos que só leem estado. */
    private const LEITURA = [
        'core show', 'pjsip show', 'pjsip list', 'sip show', 'queue show', 'dialplan show',
        'module show', 'database show', 'cdr show', 'cel show', 'manager show', 'odbc show',
        'voicemail show', 'confbridge list', 'http show', 'rtp show', 'stun show',
        'features show', 'agi show', 'ari show', 'devstate list', 'help', 'moh show',
    ];

    /** Sugestões exibidas no console. */
    private const SUGESTOES = [
        'core show version'      => 'Versão e build do Asterisk',
        'core show channels'     => 'Canais e chamadas ativas',
        'core show uptime'       => 'Há quanto tempo está no ar',
        'pjsip show endpoints'   => 'Ramais e troncos, com estado de registro',
        'pjsip show registrations' => 'Registros de saída nos provedores',
        'pjsip show aors'        => 'Contatos registrados por ramal',
        'queue show'             => 'Filas, agentes e chamadas em espera',
        'dialplan show interno'  => 'Dialplan do contexto dos ramais',
        'module show like odbc'  => 'Módulos ODBC carregados',
        'odbc show all'          => 'Conexões ODBC e seu estado',
        'manager show users'     => 'Usuários do AMI',
        'core show translation'  => 'Custo de transcodificação entre codecs',
    ];

    /** GET /api/cli/sugestoes */
    public function sugestoes(Request $req, Response $res): Response
    {
        return Resposta::json($res, [
            'sugestoes' => self::SUGESTOES,
            'pode_escrever' => Permissoes::podeAcao((array) $req->getAttribute('caps'), 'reiniciar'),
        ]);
    }

    /** POST /api/cli — executa um comando e devolve a saída */
    public function executar(Request $req, Response $res): Response
    {
        $comando = trim((string) ((array) $req->getParsedBody())['comando'] ?? '');

        if ($comando === '') {
            return Resposta::erro($res, 'Informe um comando', 422);
        }
        if (mb_strlen($comando) > 200) {
            return Resposta::erro($res, 'Comando muito longo', 422);
        }

        foreach (self::PROIBIDO as $seq) {
            if (str_contains($comando, $seq)) {
                return Resposta::erro(
                    $res,
                    "O comando contém a sequência \"{$seq}\", que permitiria sair do CLI. Recusado.",
                    422
                );
            }
        }

        $somenteLeitura = $this->ehLeitura($comando);
        if (!$somenteLeitura && !Permissoes::podeAcao((array) $req->getAttribute('caps'), 'reiniciar')) {
            return Resposta::erro(
                $res,
                'Este comando altera o estado da central. Seu perfil precisa da ação "aplicar configurações".',
                403
            );
        }

        try {
            $bruto = Ami::compartilhada()->comando($comando);
        } catch (\Throwable $e) {
            return Resposta::erro($res, 'Asterisk indisponível: ' . $e->getMessage(), 503);
        }

        Auditoria::registrar(
            $req->getAttribute('usuario'),
            $somenteLeitura ? 'cli_leitura' : 'cli_escrita',
            'admin.cli',
            $comando,
            [],
            $req->getServerParams()['REMOTE_ADDR'] ?? null
        );

        return Resposta::json($res, [
            'comando' => $comando,
            'leitura' => $somenteLeitura,
            'saida'   => $this->limpar($bruto),
        ]);
    }

    private function ehLeitura(string $comando): bool
    {
        $c = strtolower($comando);
        foreach (self::LEITURA as $prefixo) {
            if (str_starts_with($c, $prefixo)) {
                return true;
            }
        }
        return false;
    }

    /** Tira o envelope do AMI, deixando só o que o CLI imprimiu. */
    private function limpar(string $bruto): string
    {
        $linhas = [];
        foreach (explode("\n", $bruto) as $linha) {
            $linha = rtrim($linha, "\r");
            if (preg_match('/^(Response|Message|Privilege|ActionID|Event|--END COMMAND--)/', $linha)) {
                continue;
            }
            // O AMI moderno prefixa cada linha da saída com "Output: "
            $linhas[] = preg_replace('/^Output:\s?/', '', $linha);
        }

        return trim(implode("\n", $linhas));
    }
}
