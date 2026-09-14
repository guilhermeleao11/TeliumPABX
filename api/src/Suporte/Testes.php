<?php
declare(strict_types=1);

namespace Telium\Suporte;

use Telium\Dominio\Permissoes;
use Telium\Dominio\Rede;
use Telium\Dominio\Senha;
use Telium\Dominio\Stun;
use Telium\Dominio\Totp;
use Telium\Gerador\Aplicador;
use Telium\Gerador\Bloco;
use Telium\Gerador\Conferencia;
use Telium\Gerador\GeradorPjsip;
use Telium\Http\Controllers\Diagnostico;

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
        $this->grupo('Credencial do TURN', $this->turn(...));
        $this->grupo('Estado do tronco na central', $this->estadoTronco(...));
        $this->grupo('Tronco gerado', $this->troncoGerado(...));
        $this->grupo('Endereço público e faixas locais', $this->nat(...));

        if ($comBanco) {
            $this->grupo('Banco e esquema', $this->banco(...));
            $this->grupo('Conferência do cadastro', $this->conferencia(...));
            $this->grupo('Portas da API', $this->rotas(...));
        }

        if (Ami::tentarComando('core show version') !== null) {
            $this->grupo('Aplicar no Asterisk', $this->aplicar(...));
            $this->grupo('Portas dos transportes', $this->portasDoTransporte(...));
        } else {
            echo "\n  Aplicar no Asterisk\n"
               . "    \033[33m!\033[0m sem AMI — este grupo precisa do Asterisk no ar\n";
        }

        return ['passou' => $this->passou, 'falhou' => $this->falhou, 'erros' => $this->erros];
    }

    // ---------------------------------------------------------------
    /**
     * Tronco com usuário e senha em branco não pode virar auth.
     *
     * O Asterisk recusa o objeto ("No plain text or digest password
     * found") e então tudo que aponta para ele aponta para o nada: o log
     * diz "Couldn't find auth", a registration recebe 401 e para de vez.
     * A senha sumia sozinha — salvar o tronco com o campo em branco
     * apagava o que estava guardado.
     */
    private function troncoGerado(): void
    {
        $base = [
            'nome' => 'Operadora', 'host' => 'sip.op.com.br', 'porta' => 5060,
            'transporte' => 'udp', 'contexto_entrada' => 'de-tronco',
            'codecs' => 'alaw,ulaw', 'usuario' => '112658668', 'senha' => 'segredo',
            'registrar' => 1, 'from_user' => '', 'from_domain' => '', 'cid_saida' => '',
        ];

        $gerar = static function (array $mudancas) use ($base): string {
            $b = new Bloco();
            (new GeradorPjsip())->tronco($b, [...$base, ...$mudancas]);
            return $b->texto();
        };

        $comSenha = $gerar([]);
        $this->ok(str_contains($comSenha, 'type = auth'), 'tronco com senha gera o auth');
        $this->ok(str_contains($comSenha, 'outbound_auth = Operadora'), 'o endpoint aponta para o auth');
        $this->ok(str_contains($comSenha, 'type = registration'), 'tronco com senha gera a registration');
        $this->ok(
            str_contains($comSenha, 'contact_user = 112658668'),
            'o Contact do registro sai com a conta, não com "s"'
        );

        $semSenha = $gerar(['senha' => '']);
        $this->ok(!str_contains($semSenha, 'type = auth'), 'sem senha não gera auth');
        $this->ok(
            !str_contains($semSenha, 'outbound_auth ='),
            'sem senha o endpoint não aponta para um auth que não existe'
        );
        $this->ok(
            !str_contains($semSenha, 'type = registration'),
            'sem senha não gera registration que morreria no primeiro 401'
        );

        $semUsuario = $gerar(['usuario' => '', 'senha' => '']);
        $this->ok(!str_contains($semUsuario, 'type = auth'), 'tronco por IP não gera auth');
        $this->ok(str_contains($semUsuario, 'type = identify'), 'tronco por IP ainda é identificado pelo host');
    }

    /**
     * Central atrás de NAT.
     *
     * Sem endereço público o REGISTER sai anunciando o IP interno e a
     * operadora responde para um endereço que não existe na internet.
     * Sem faixa local, o contrário: a central passa a anunciar o
     * endereço de fora até para o telefone da mesa ao lado.
     */
    private function nat(): void
    {
        $this->ok(
            Rede::faixas("10.0.0.0/8, 192.168.0.0/16\n172.16.0.0/12")
                === ['10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12'],
            'a lista de faixas aceita vírgula e quebra de linha'
        );
        $this->ok(Rede::faixas('   ') === [], 'lista em branco não vira faixa vazia');

        $this->ok(Rede::faixaValida('192.168.0.0/16'), 'aceita CIDR');
        $this->ok(Rede::faixaValida('192.168.0.0/255.255.0.0'), 'aceita máscara por extenso');
        $this->ok(Rede::faixaValida('10.0.0.1'), 'aceita endereço solto');
        $this->ok(!Rede::faixaValida('192.168.0.0/99'), 'recusa máscara impossível');
        $this->ok(!Rede::faixaValida('rede-interna'), 'recusa o que não é endereço');

        // Resposta STUN montada à mão: cabeçalho, cookie, transação e o
        // XOR-MAPPED-ADDRESS de 203.0.113.9:54321.
        $transacao = str_repeat("\x01", 12);
        $ip = inet_pton('203.0.113.9') ^ pack('N', 0x2112A442);
        $atributo = pack('nn', 0x0020, 8) . "\x00\x01" . pack('n', 54321 ^ 0x2112) . $ip;
        $pacote = pack('nnN', 0x0101, strlen($atributo), 0x2112A442) . $transacao . $atributo;

        $this->ok(
            Stun::lerResposta($pacote, $transacao) === '203.0.113.9',
            'lê o endereço público da resposta do STUN'
        );
        $this->ok(
            Stun::lerResposta($pacote, str_repeat("\x02", 12)) === null,
            'resposta de outra pergunta não é aceita'
        );
        $this->ok(Stun::lerResposta('curto', $transacao) === null, 'pacote truncado não derruba nada');
    }

    // ---------------------------------------------------------------
    /**
     * A tela de troncos diz se cada tronco chegou mesmo no Asterisk, e
     * essa resposta sai de "pjsip show registrations". A saída é
     * tabular, com coluna que aparece e some, e a armadilha é o
     * "Unregistered", que contém "Registered" — procurar por substring
     * dizia "registrado" justamente quando a operadora tinha recusado.
     */
    private function estadoTronco(): void
    {
        $registrado = ' Operadora-reg/sip:sip.op.com.br      Operadora      Registered';
        $recusado   = ' Operadora-reg/sip:sip.op.com.br      Operadora      Rejected';
        $semRegistro = ' Operadora-reg/sip:127.0.0.1:5081     Operadora      Unregistered      (exp. 3s)';
        $semAuth    = ' Operadora-reg/sip:sip.op.com.br                     Registered';
        $enviando   = ' Operadora-reg/sip:sip.op.com.br      Operadora      Auth. Sent';

        $this->ok(
            Diagnostico::estadoDoRegistro($registrado, 'Operadora') === 'Registered',
            'lê o tronco registrado na operadora'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro($semRegistro, 'Operadora') === 'Unregistered',
            '"Unregistered" não passa por registrado'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro($recusado, 'Operadora') === 'Rejected',
            'lê a recusa da operadora'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro($semAuth, 'Operadora') === 'Registered',
            'lê o registro do tronco que não autentica'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro($enviando, 'Operadora') === 'Auth. Sent',
            'lê o registro em andamento'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro($registrado, 'Operadora2') === '',
            'não confunde um tronco com outro de nome parecido'
        );
        $this->ok(
            Diagnostico::estadoDoRegistro('No objects found.', 'Operadora') === '',
            'tronco sem linha de registro fica sem estado'
        );

        $contatos = "  Contact:  Operadora/sip:127.0.0.1:5081   aac507088c Unavail        -nan";
        $this->ok(
            Diagnostico::estadoDoContato($contatos, 'Operadora') === 'Unavail',
            '"Unavail" não passa por "Avail"'
        );
        $this->ok(
            Diagnostico::estadoDoContato(
                "  Contact:  Operadora/sip:sip.op.com.br   aac507088c Avail        21.500",
                'Operadora'
            ) === 'Avail',
            'lê o contato que respondeu ao teste'
        );
        $this->ok(
            Diagnostico::estadoDoContato(
                "  Contact:  Operadora/sip:sip.op.com.br   aac507088c NonQual        nan",
                'Operadora'
            ) === 'NonQual',
            'lê o tronco sem teste de resposta configurado'
        );
        $this->ok(
            Diagnostico::estadoDoContato($contatos, 'Oper') === '',
            'não confunde o contato de um tronco com o de outro'
        );

        $auths = "     Auth:  Magnus-SPO/112658668\n     Auth:  1000/1000";
        $this->ok(
            !Aplicador::recargaDeuCerto("Response: Success\nOutput: No such module 'res_pjsip.so'"),
            'módulo ausente não passa por recarga bem-sucedida'
        );
        $this->ok(
            Aplicador::recargaDeuCerto("Response: Success\nOutput: Module 'res_pjsip.so' reloaded successfully."),
            'recarga de verdade continua contando como sucesso'
        );

        $this->ok(
            Diagnostico::temAuth($auths, 'Magnus-SPO'),
            'vê a autenticação do tronco carregada na central'
        );
        $this->ok(
            !Diagnostico::temAuth($auths, 'Magnus'),
            'não confunde "Magnus" com "Magnus-SPO"'
        );
        $this->ok(
            !Diagnostico::temAuth('No objects found.', 'Magnus-SPO'),
            'central sem nenhum auth não passa por autenticada'
        );

        $endpoints = " Endpoint:  Operadora/1140041000    Unavailable   0 of inf\n"
                   . " Endpoint:  1001/1001               Unavailable   0 of 2";
        $this->ok(
            preg_match('/^\s*Endpoint:\s+' . preg_quote('Operadora', '/') . '[\/\s]/mi', $endpoints) === 1,
            'reconhece o tronco publicado em "pjsip show endpoints"'
        );
        $this->ok(
            preg_match('/^\s*Endpoint:\s+' . preg_quote('Oper', '/') . '[\/\s]/mi', $endpoints) !== 1,
            'não dá o tronco por publicado pelo começo do nome'
        );
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

    /**
     * A credencial do TURN segue o esquema use-auth-secret do coturn:
     * usuário é a hora em que ela morre, senha é o HMAC-SHA1 disso com
     * o segredo do servidor. Errar aqui não quebra nada visível — só
     * faz o relay recusar, e a chamada fica muda sem explicação.
     */
    private function turn(): void
    {
        $segredo = 'segredo-de-teste';
        $validade = 1789410842;
        $usuario = $validade . ':telium';
        $senha = base64_encode(hash_hmac('sha1', $usuario, $segredo, true));

        $this->ok(
            $senha === base64_encode(hash_hmac('sha1', $usuario, $segredo, true)),
            'a senha do TURN é o HMAC-SHA1 do usuário, em base64'
        );
        $this->ok(strlen(base64_decode($senha, true) ?: '') === 20, 'o HMAC tem os 20 bytes do SHA1');
        $this->ok(
            $senha !== base64_encode(hash_hmac('sha1', $usuario, 'outro-segredo', true)),
            'segredo diferente gera senha diferente'
        );
        $this->ok(
            str_contains($usuario, ':'),
            'o usuário carrega a validade antes dos dois-pontos'
        );
    }

    /**
     * O botão "aplicar configurações" funciona?
     *
     * Este grupo nasceu de um defeito que passou por toda a auditoria:
     * a lista de recargas mandava "pjsip reload", que não existe no
     * Asterisk 22. O console dizia que tinha aplicado, e ramal e tronco
     * novos nunca chegavam à central. Os testes anteriores geravam os
     * arquivos e recarregavam à mão — o caminho do botão nunca era
     * exercido.
     */
    private function aplicar(): void
    {
        // Antes de qualquer coisa: o dialplan está de pé?
        //
        // Basta um arquivo do #include faltar para o pbx_config recusar
        // carregar, e aí a central não roteia mais nada. O sintoma que
        // aparece primeiro é enganoso — "dialplan reload" some, porque
        // quem registra esse comando é o módulo que não subiu —, então
        // a conferência começa pela causa, não pelo sintoma.
        $modulo = (string) Ami::tentarComando('module show like pbx_config');
        $this->ok(
            str_contains($modulo, 'Running') && !str_contains($modulo, 'Not Running'),
            'o módulo do dialplan está carregado'
            . (str_contains($modulo, 'Not Running')
                ? ' — NÃO ESTÁ: a central não roteia nenhuma chamada neste estado. '
                . 'Quase sempre é um arquivo do #include faltando em '
                . 'extensions.conf; procure "does not exist" no log do Asterisk.'
                : '')
        );

        $contextos = (string) Ami::tentarComando('dialplan show');
        preg_match('/in (\d+) contexts/', $contextos, $m);
        $quantos = (int) ($m[1] ?? 0);
        $this->ok($quantos >= 10, "o dialplan tem os contextos do Telium ({$quantos} carregados)");

        // Cada comando da lista existe nesta versão do Asterisk?
        //
        // A falha mostra o que o Asterisk respondeu: sem isso, o teste
        // diz que o comando foi recusado e não diz por quê, e quem está
        // instalando a central fica sem saber o que fazer com a
        // informação.
        foreach (Aplicador::comandosDeRecarga() as $comando => $descricao) {
            $r = (string) Ami::tentarComando($comando);
            $conhece = !str_contains(strtolower($r), 'no such command')
                && !str_contains(strtolower($r), 'does not support reload');

            $this->ok(
                $conhece,
                "o Asterisk conhece a recarga de {$descricao} (\"{$comando}\")"
                . ($conhece ? '' : ' — respondeu: ' . $this->resumo($r))
            );
        }

        // Família vazia responde erro no AMI e não é problema: numa
        // central sem lista negra, todas estão vazias.
        $ami = Ami::compartilhada();
        $r = $ami->acao(['Action' => 'DBDelTree', 'Family' => 'telium-teste-inexistente']);
        $this->ok(
            str_contains(strtolower($r), 'not found') || str_contains(strtolower($r), 'success'),
            'apagar uma família vazia na base do Asterisk não é tratado como falha'
        );

        // Dez ações seguidas: é onde o casamento por pedaço de texto
        // quebrava, porque "telium-4" casa com "ActionID: telium-47" e
        // as respostas passavam a sair trocadas.
        $ids = [];
        $certas = true;
        for ($i = 1; $i <= 12; $i++) {
            $r = $ami->acao(['Action' => 'DBPut', 'Family' => 'telium-teste',
                             'Key' => "k{$i}", 'Val' => "v{$i}"]);
            if (preg_match('/ActionID:\s*(\S+)/', $r, $m) !== 1 || isset($ids[$m[1]])) {
                $certas = false;
                break;
            }
            $ids[$m[1]] = true;
        }
        $ami->acao(['Action' => 'DBDelTree', 'Family' => 'telium-teste']);
        $this->ok($certas, 'doze ações seguidas recebem cada uma a sua resposta');

        // E o caminho inteiro, que é o que o botão faz. Duas vezes: a
        // segunda é a que pegava o desalinhamento herdado da primeira.
        (new Aplicador())->aplicar(null, []);
        $resultado = (new Aplicador())->aplicar(null, []);
        $falharam = array_keys(array_filter(
            $resultado['etapas'],
            static fn (string $e): bool => $e !== 'ok'
        ));

        $this->ok(
            (bool) $resultado['sucesso'],
            'aplicar a configuração inteira termina em sucesso'
            . ($falharam === [] ? '' : ' — falhou em: ' . implode(', ', $falharam)
                                     . "\n      saída do Asterisk: " . $this->resumo($resultado['saida']))
        );
    }

    /**
     * A parte útil de uma resposta do AMI, numa linha.
     *
     * O envelope do protocolo e o "Output:" de cada linha não ajudam
     * quem está lendo o resultado de um teste às duas da manhã.
     */
    /**
     * As portas que a API gera são as que o Asterisk realmente escuta?
     *
     * O bind dos transportes passou a sair da API, a partir do .env que
     * o instalador escreve. Se os dois discordarem, nada quebra na hora
     * — o Asterisk avisa que transporte não é totalmente recarregável e
     * mantém a porta antiga —, e a central sobe na porta errada só no
     * próximo reinício, longe da mudança que causou.
     */
    private function portasDoTransporte(): void
    {
        // A resposta vem com o envelope do AMI, com "Output: " na
        // frente de cada linha — sem tirá-lo, a âncora de início de
        // linha nunca casa.
        $saida = Diagnostico::semEnvelope((string) (Ami::tentarComando('pjsip show transports') ?? ''));
        $esperado = [
            'transport-udp' => Rede::portaSip(),
            'transport-tls' => Rede::portaSipTls(),
            'transport-wss' => Rede::portaWs(),
        ];

        foreach ($esperado as $nome => $porta) {
            $achado = [];
            $tem = preg_match(
                '/^Transport:\s+' . preg_quote($nome, '/') . '\s+\S+\s+\d+\s+\d+\s+\S+:(\d+)/mi',
                $saida,
                $achado
            ) === 1;

            $this->ok(
                $tem && (int) $achado[1] === $porta,
                sprintf(
                    '%s escuta na porta que a API gera (%d)%s',
                    $nome,
                    $porta,
                    $tem ? '' : ' — transporte não encontrado na central'
                )
            );
        }
    }

    private function resumo(string $bruto): string
    {
        $linhas = [];
        foreach (explode("\n", $bruto) as $linha) {
            $linha = trim(preg_replace('/^Output:\s?/', '', rtrim($linha, "\r")) ?? '');
            if ($linha === '' || preg_match('/^(Response|ActionID|Message|Privilege|--END)/', $linha)) {
                continue;
            }
            $linhas[] = $linha;
            if (count($linhas) === 3) {
                break;
            }
        }

        return $linhas === [] ? '(resposta vazia — o AMI não respondeu)' : implode(' | ', $linhas);
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
