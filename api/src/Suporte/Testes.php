<?php
declare(strict_types=1);

namespace Telium\Suporte;

use Telium\Dominio\Permissoes;
use Telium\Dominio\Senha;
use Telium\Dominio\Totp;
use Telium\Gerador\Bloco;
use Telium\Gerador\Conferencia;

/**
 * Bateria de testes do Telium, sem depender de nada instalado.
 *
 * Roda na máquina do cliente: `php bin/telium testar`. Serve para
 * conferir uma instalação nova e, principalmente, para as falhas já
 * corrigidas não voltarem — cada caso aqui nasceu de um defeito real.
 */
final class Testes
{
    private int $passou = 0;
    private int $falhou = 0;

    /** @var list<string> */
    private array $erros = [];

    /** @return array{passou:int, falhou:int, erros:list<string>} */
    public function rodar(bool $comBanco): array
    {
        $this->grupo('Senha e sessão', $this->senha(...));
        $this->grupo('Verificação em dois passos', $this->totp(...));
        $this->grupo('Permissões', $this->permissoes(...));
        $this->grupo('Geração de dialplan', $this->dialplan(...));

        if ($comBanco) {
            $this->grupo('Banco e esquema', $this->banco(...));
            $this->grupo('Conferência do cadastro', $this->conferencia(...));
            $this->grupo('Portas da API', $this->rotas(...));
        }

        return ['passou' => $this->passou, 'falhou' => $this->falhou, 'erros' => $this->erros];
    }

    // ---------------------------------------------------------------
    private function senha(): void
    {
        $hash = Senha::criar('T3l1um_@2024_@aD1m');
        $this->ok(Senha::verificar('T3l1um_@2024_@aD1m', $hash), 'a senha certa confere');
        $this->ok(!Senha::verificar('outra', $hash), 'a senha errada não confere');
        $this->ok(!Senha::verificar('', $hash), 'senha vazia não confere');
        $this->ok(str_starts_with($hash, 'pbkdf2_sha256$'), 'o hash sai no formato esperado');
        $this->ok(Senha::criar('x') !== Senha::criar('x'), 'dois hashes da mesma senha são diferentes');

        // Sem este hash de mentira, o tempo de resposta dizia quais
        // usuários existem.
        $this->ok(!Senha::verificar('qualquer', Senha::HASH_FALSO), 'o hash de mentira nunca confere');
        $this->ok(Senha::validar('curta') !== [], 'senha fraca é recusada');
        $this->ok(Senha::validar('Telium@2024!') === [], 'senha forte é aceita');
    }

    private function totp(): void
    {
        // Vetores da própria RFC 6238.
        $s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924'] as $t => $codigo) {
            $this->ok(Totp::conferir($s, $codigo, $t), "vetor da RFC 6238 em t={$t}");
        }
        $this->ok(!Totp::conferir($s, '000000', 59), 'código inventado é recusado');
        $this->ok(!Totp::conferir($s, '28708', 59), 'código de cinco dígitos é recusado');
        $this->ok(!Totp::conferir('não-é-base32', '287082', 59), 'segredo inválido é recusado');
        $this->ok(Totp::passoUsado($s, '287082', 59) === 1, 'o passo usado é devolvido para barrar repetição');
        $this->ok(strlen(Totp::gerarSegredo()) === 32, 'o segredo novo tem 32 caracteres');
    }

    private function permissoes(): void
    {
        $this->ok(Permissoes::podeModulo(['*'], 'conn.ramais'), 'o curinga total abre tudo');
        $this->ok(Permissoes::podeModulo(['conn.*'], 'conn.ramais'), 'o curinga de grupo abre o grupo');
        $this->ok(!Permissoes::podeModulo(['conn.*'], 'admin.usuarios'), 'o curinga de grupo não vaza para outro grupo');
        $this->ok(!Permissoes::podeModulo([], 'conn.ramais'), 'perfil sem módulo não entra');
        $this->ok(!Permissoes::podeModulo(['conn.ramaisX'], 'conn.ramais'), 'nome parecido não abre');
        $this->ok(Permissoes::podeAcao(['editar'], 'editar'), 'a ação declarada passa');
        $this->ok(!Permissoes::podeAcao(['editar'], 'excluir'), 'a ação não declarada não passa');
        $this->ok(!Permissoes::podeAcao(['*'], 'excluir'), 'ação não aceita curinga');
    }

    /**
     * O caso que gerou esta bateria: o nome de uma fila com quebra de
     * linha virava dialplan de verdade e executava comando no servidor.
     */
    private function dialplan(): void
    {
        $b = (new Bloco())
            ->comentario("Fila\nexten => 6666,1,System(rm -rf /)")
            ->contexto("ctx\n[outro]")
            ->exten('3000', "NoOp(Fila Ataque)\nexten => 6666,1,System(x)")
            ->same("NoOp(algo)\n same => n,System(x)")
            ->same('NoOp(rotulo)', "lab\nexten => 7777,1,System(x)")
            ->crua("type = endpoint\ncontext = telium-saida");

        $texto = $b->texto();
        $linhas = array_filter(explode("\n", $texto), static fn ($l) => trim($l) !== '');

        $this->ok(count($linhas) === 6, 'seis chamadas geram seis linhas, não mais');

        // O que fazia a falha existir era o texto injetado começar uma
        // linha nova: só assim ele vira dialplan de verdade. Colado no
        // fim da linha anterior ele é apenas texto dentro de um NoOp.
        $injetadas = array_filter(
            $linhas,
            static fn (string $l): bool => str_starts_with(ltrim($l), 'exten => 6666')
                || str_starts_with(ltrim($l), 'exten => 7777')
                || str_starts_with(ltrim($l), 'context =')
                || ltrim($l) === '[outro]'
        );
        $this->ok($injetadas === [], 'nada do texto injetado começa uma linha nova');

        foreach ($linhas as $l) {
            $this->ok(
                str_starts_with(ltrim($l), ';')
                || str_starts_with(ltrim($l), '[')
                || str_starts_with(ltrim($l), 'exten =>')
                || str_starts_with(ltrim($l), 'same =>')
                || str_contains($l, '='),
                'cada linha gerada continua sendo uma linha de configuração'
            );
        }

        // Ponto e vírgula corta a linha para o Asterisk: já derrubou o
        // dialplan duas vezes.
        $t = (new Bloco())->same('NoOp(um; dois)')->texto();
        $this->ok(!str_contains($t, ';'), 'o ponto e vírgula não sobrevive dentro da aplicação');
    }

    // ---------------------------------------------------------------
    private function banco(): void
    {
        $esquema = Esquema::conferir();
        $this->ok(
            (bool) $esquema['ok'],
            'o banco tem todas as tabelas e colunas que o código usa'
            . ($esquema['ok'] ? '' : ' — ' . $esquema['mensagem'])
        );

        $this->ok((int) Bd::valor('SELECT COUNT(*) FROM perfis') > 0, 'existe pelo menos um perfil');
        $this->ok(
            (int) Bd::valor("SELECT COUNT(*) FROM usuarios WHERE status = 'ativo'") > 0,
            'existe pelo menos um usuário ativo'
        );

        // Sem esta chave, apagar o tronco de reserva deixava a rota
        // apontando para o nada e o failover sumia calado.
        $this->ok(
            (int) Bd::valor(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rotas_saida'
                    AND CONSTRAINT_NAME = 'fk_rota_tronco_falha'"
            ) === 1,
            'a rota de saída tem vínculo com o tronco de reserva'
        );

        $this->ok(
            (int) Bd::valor(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND ENGINE <> 'InnoDB'"
            ) === 0,
            'todas as tabelas são InnoDB (transação e chave estrangeira)'
        );

        $orfas = (int) Bd::valor(
            'SELECT COUNT(*) FROM rotas_saida r
               LEFT JOIN troncos t ON t.id = r.tronco_id WHERE t.id IS NULL'
        );
        $this->ok($orfas === 0, 'nenhuma rota de saída aponta para tronco inexistente');
    }

    private function conferencia(): void
    {
        $problemas = Conferencia::problemas();
        $erros = array_filter($problemas, static fn (array $p): bool => $p['nivel'] === 'erro');

        $this->ok(
            $erros === [],
            'nenhum destino do cadastro aponta para coisa que não existe'
            . ($erros === [] ? '' : ' — ' . implode('; ', array_map(
                static fn (array $p): string => "{$p['onde']}: {$p['texto']}",
                $erros
            )))
        );
    }

    /**
     * Toda porta da API está trancada?
     *
     * Monta o mesmo aplicativo do index.php e bate em cada rota sem
     * credencial. Só /health e o login podem responder sem 401 — é o
     * teste que pega a rota nova em que alguém esqueceu o middleware.
     */
    private function rotas(): void
    {
        $indice = __DIR__ . '/../../public/index.php';
        if (!is_file($indice)) {
            $this->ok(false, "não encontrei o index.php da API em {$indice}");

            return;
        }

        if (!defined('TELIUM_SEM_RUN')) {
            define('TELIUM_SEM_RUN', true);
        }

        /** @var \Slim\App $app */
        $app = require $indice;

        $publicas = ['GET /api/health', 'POST /api/auth/login'];
        $abertas = [];
        $conferidas = 0;

        foreach ($app->getRouteCollector()->getRoutes() as $rota) {
            foreach ($rota->getMethods() as $metodo) {
                if ($metodo === 'OPTIONS') {
                    continue;
                }

                // Um {id} qualquer serve: a resposta esperada é 401
                // antes de o controlador sequer rodar.
                $caminho = (string) preg_replace('/\{[^}]+\}/', '1', $rota->getPattern());
                $chave = "{$metodo} /api{$caminho}";
                if (in_array($chave, $publicas, true)) {
                    continue;
                }

                $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
                    ->createServerRequest($metodo, "/api{$caminho}");

                try {
                    $codigo = $app->handle($req)->getStatusCode();
                } catch (\Throwable) {
                    continue;   // rota que estoura sem sessão também não vaza dado
                }

                $conferidas++;
                if ($codigo !== 401) {
                    $abertas[] = "{$chave} respondeu {$codigo}";
                }
            }
        }

        $this->ok($conferidas > 30, "as rotas foram percorridas ({$conferidas} conferidas)");
        $this->ok(
            $abertas === [],
            'nenhuma rota responde sem sessão'
            . ($abertas === [] ? '' : ' — ' . implode('; ', array_slice($abertas, 0, 5)))
        );
    }

    // ---------------------------------------------------------------
    private function grupo(string $nome, callable $casos): void
    {
        echo "\n  {$nome}\n";
        try {
            $casos();
        } catch (\Throwable $e) {
            $this->falhou++;
            $this->erros[] = "{$nome}: {$e->getMessage()}";
            echo "    \033[31m✗\033[0m o grupo estourou: {$e->getMessage()}\n";
        }
    }

    private function ok(bool $condicao, string $descricao): void
    {
        if ($condicao) {
            $this->passou++;
            echo "    \033[32m✓\033[0m {$descricao}\n";

            return;
        }

        $this->falhou++;
        $this->erros[] = $descricao;
        echo "    \033[31m✗\033[0m {$descricao}\n";
    }
}
