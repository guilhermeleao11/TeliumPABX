<?php
declare(strict_types=1);

namespace Telium\Suporte;

use Telium\Dominio\BackupRemoto;
use Telium\Dominio\Certificado;
use Telium\Dominio\Permissoes;
use Telium\Dominio\Provisionamento;
use Telium\Dominio\Rede;
use Telium\Dominio\Senha;
use Telium\Dominio\Tarifa;
use Telium\Dominio\Stun;
use Telium\Dominio\Totp;
use Telium\Gerador\Aplicador;
use Telium\Gerador\Bloco;
use Telium\Gerador\Conferencia;
use Telium\Gerador\Destino;
use Telium\Gerador\GeradorDialplan;
use Telium\Gerador\GeradorPjsip;
use Telium\Gerador\GeradorTransportes;
use Telium\Http\Middleware\Permissao;
use Telium\Http\Controllers\Diagnostico;
use Telium\Http\Controllers\Portal;

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
        $this->grupo('Permissão por referência', $this->permissaoReferencia(...));
        $this->grupo('Destinos de chamada', $this->destinos(...));
        $this->grupo('Codecs', $this->codecs(...));
        $this->grupo('Áudios que o dialplan toca', $this->sons(...));
        $this->grupo('Diretórios de gravação e recado', $this->spool(...));
        $this->grupo('Softphone do navegador', $this->webrtc(...));

        if ($comBanco) {
            $this->grupo('Banco e esquema', $this->banco(...));
            $this->grupo('Conferência do cadastro', $this->conferencia(...));
            $this->grupo('Ramais WebRTC gerados', $this->webrtcGerado(...));
            $this->grupo('Perfis de fábrica', $this->perfisDeFabrica(...));
            $this->grupo('Faixas de horário', $this->horarios(...));
            $this->grupo('Tarifação', $this->tarifacao(...));
            $this->grupo('Provisionamento de telefones', $this->provisionamento(...));
            $this->grupo('Saída do backup', $this->backup(...));
            $this->grupo('Certificados TLS', $this->certificados(...));
            $this->grupo('Portas da API', $this->rotas(...));
            $this->grupo('Porta do provisionamento', $this->portaDoProvisionamento(...));
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
     * Codec que o Asterisk não traduz só funciona ponta a ponta.
     *
     * O g729 de fábrica é passagem: a tabela de tradução nem lista o
     * codec. Com g729 no tronco e opus no ramal do navegador, a operadora
     * atende e a chamada cai na hora, com "Unable to find a codec
     * translation path" no log — depois de a chamada ter sido tarifada.
     */
    private function codecs(): void
    {
        $lista = Conferencia::listaCodecs(' ALAW , ulaw;g729  ');
        $this->ok(
            $lista === ['alaw', 'ulaw', 'g729'],
            'a lista de codecs aceita espaço, ponto e vírgula e maiúscula'
        );
        $this->ok(Conferencia::listaCodecs('') === [], 'lista vazia não vira codec vazio');

        // O padrão de fábrica não pode trazer o codec que derruba chamada.
        // O MariaDB devolve o padrão entre aspas simples: sem tirá-las,
        // "g729'" não casa com "g729" e a conferência passa sem conferir.
        $padrao = trim((string) Bd::valor(
            "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'troncos' AND COLUMN_NAME = 'codecs'"
        ), "'\"");
        $this->ok(
            !in_array('g729', Conferencia::listaCodecs($padrao), true),
            "o codec padrão do tronco não traz g729 (está \"{$padrao}\")"
        );
    }

    // ---------------------------------------------------------------
    /**
     * O Asterisk consegue escrever onde o dialplan manda gravar?
     *
     * VoiceMail, MixMonitor, Dictate e ReceiveFAX criam subdiretório na
     * hora da chamada. O "make install" cria esses diretórios como root
     * e o Asterisk roda como asterisk: sem o dono certo, a gravação
     * falha no meio da ligação e a única pista é um WARNING —
     *
     *   ast_mkdir '/var/spool/asterisk/voicemail/telium/1000/tmp'
     *   failed: Permission denied
     *
     * — enquanto quem ligou é desligado em vez de deixar recado.
     */
    private function spool(): void
    {
        $usuario = trim((string) Ambiente::get('ASTERISK_USUARIO', 'asterisk')) ?: 'asterisk';
        $grupo   = trim((string) Ambiente::get('ASTERISK_GRUPO', 'asterisk')) ?: 'asterisk';

        // No servidor a API e o Asterisk dividem a máquina e este é o
        // caminho de fábrica; na bancada são dois contêineres e o spool
        // vem montado em outro lugar.
        $spool = rtrim((string) Ambiente::get('ASTERISK_SPOOL_DIR', '/var/spool/asterisk'), '/');

        $precisa = [
            "{$spool}/voicemail" => 'recado do correio de voz',
            "{$spool}/monitor"   => 'gravação de chamada',
            "{$spool}/dictate"   => 'ditado',
            "{$spool}/fax"       => 'fax recebido',
        ];

        // Por número, não por nome: dentro de um contêiner o UID do dono
        // costuma não existir na base de usuários, e posix_getpwuid
        // devolve nada — comparar nomes daria "?" e reprovaria uma
        // instalação correta.
        $conta = posix_getpwnam($usuario);
        $grupoInfo = posix_getgrnam($grupo);

        if ($conta === false) {
            $this->ok(false, "o usuário {$usuario} não existe nesta máquina — confira ASTERISK_USUARIO");

            return;
        }

        $uid = (int) $conta['uid'];
        $gid = $grupoInfo === false ? -1 : (int) $grupoInfo['gid'];

        foreach ($precisa as $dir => $paraQue) {
            if (!is_dir($dir)) {
                $this->ok(false, "{$dir} não existe — {$paraQue} vai falhar no meio da chamada");
                continue;
            }

            $modo = fileperms($dir);
            $dono = (int) fileowner($dir);
            $gr   = (int) filegroup($dir);

            // root escreve em qualquer lugar; para os demais, o dono
            // precisa poder escrever, ou o grupo.
            $escreve = $uid === 0
                    || ($dono === $uid && ($modo & 0200) !== 0)
                    || ($gid >= 0 && $gr === $gid && ($modo & 0020) !== 0);

            $this->ok(
                $escreve,
                $escreve
                    ? sprintf('%s: o Asterisk escreve — %s', $dir, $paraQue)
                    : sprintf(
                        '%s é %d:%d %s — o Asterisk roda como %s (%d:%d) e não vai gravar %s',
                        $dir,
                        $dono,
                        $gr,
                        substr(sprintf('%o', $modo), -4),
                        $usuario,
                        $uid,
                        $gid,
                        $paraQue
                    )
            );
        }
    }

    // ---------------------------------------------------------------
    /**
     * Os áudios que o dialplan manda tocar existem no disco?
     *
     * Playback de arquivo que não existe não dá erro: o Asterisk registra
     * uma linha no log e segue. A URA fica muda, o aviso de "todos os
     * circuitos ocupados" não toca, e quem liga acha que a central está
     * quebrada. A seleção dos sons na compilação é "falha aqui não
     * interrompe" de propósito — então a conferência tem de ser aqui,
     * na máquina do cliente.
     */
    private function sons(): void
    {
        $conf = rtrim((string) Ambiente::get('ASTERISK_CONF_DIR', '/etc/asterisk'), '/');
        $sons = rtrim((string) Ambiente::get('ASTERISK_SONS_DIR', '/var/lib/asterisk/sounds'), '/');

        if (!is_dir($sons)) {
            $this->ok(false, "o diretório de áudios {$sons} não existe — o Asterisk foi instalado sem sons");

            return;
        }

        $arquivos = array_merge(
            glob("{$conf}/extensions.conf") ?: [],
            glob(rtrim((string) Ambiente::get('ASTERISK_GERADO_DIR', "{$conf}/telium"), '/') . '/*.conf') ?: []
        );
        if ($arquivos === []) {
            $this->ok(false, "não achei dialplan em {$conf} para saber quais áudios conferir");

            return;
        }

        $pedidos = [];
        foreach ($arquivos as $arquivo) {
            $texto = (string) file_get_contents($arquivo);
            $achados = [];
            preg_match_all('/\b(Playback|Background|BackGround)\(([^)\n]*)/i', $texto, $achados, PREG_SET_ORDER);
            foreach ($achados as $a) {
                // Só o primeiro argumento é a lista de arquivos; do
                // segundo em diante são opções. E a lista pode encadear
                // vários com "&" — conferir só o primeiro deixava passar
                // "please-enter-your&extension&then&press-pound" com três
                // arquivos nunca verificados, que tocariam pela metade.
                //
                // Read() fica de fora: o primeiro argumento dele é o nome
                // da variável, não um arquivo.
                $lista = explode(',', $a[2])[0];
                foreach (explode('&', $lista) as $parte) {
                    $nome = trim($parte);
                    if ($nome !== '' && preg_match('/^[A-Za-z0-9\/_-]+$/', $nome) === 1
                        && !str_starts_with($nome, '$')) {
                        $pedidos[$nome] = true;
                    }
                }
            }
        }

        // No idioma configurado, não em qualquer um. Com languageprefix,
        // o Asterisk procura sounds/<idioma>/arquivo e, se não achar, cai
        // no inglês sem dizer nada: aceitar qualquer diretório deixaria
        // passar uma central que responde em inglês com a configuração
        // dizendo pt_BR.
        $idioma = trim((string) Ambiente::get('ASTERISK_IDIOMA', 'en'));
        $faltando = [];
        foreach (array_keys($pedidos) as $nome) {
            $no_idioma = glob("{$sons}/{$idioma}/{$nome}.*") ?: [];
            $solto = glob("{$sons}/{$nome}.*") ?: [];
            if ($no_idioma === [] && $solto === []) {
                $faltando[] = $nome;
            }
        }

        $this->ok(
            $pedidos !== [],
            sprintf('o dialplan pede %d áudios', count($pedidos))
        );
        $this->ok(
            $faltando === [],
            $faltando === []
                ? "todos os áudios que o dialplan toca existem em {$idioma}"
                : sprintf(
                    'áudio que o dialplan toca e não existe em %s/%s: %s',
                    $sons,
                    $idioma,
                    implode(', ', array_slice($faltando, 0, 8)) . (count($faltando) > 8 ? '…' : '')
                )
        );

        // Falar português é mais do que ter estes arquivos: SayNumber,
        // VoiceMail, ConfBridge e a fila tocam áudios próprios, que o
        // dialplan não cita. Sem o conjunto de dígitos, a central lê
        // número em inglês no meio de uma frase em português.
        // O pacote em inglês instala na RAIZ de sounds/, não em
        // sounds/en/ — é o idioma de fábrica do Asterisk. Procurar só no
        // diretório do idioma acusaria falta numa instalação correta.
        $digitos = array_merge(
            glob("{$sons}/{$idioma}/digits/*") ?: [],
            glob("{$sons}/digits/*") ?: []
        );
        $this->ok(
            count($digitos) >= 20,
            count($digitos) >= 20
                ? sprintf('o conjunto de dígitos está instalado (%d arquivos)', count($digitos))
                : sprintf(
                    'faltam os dígitos (%d arquivos em %s/digits e %s/%s/digits) — '
                    . 'SayNumber, correio de voz e fila não vão saber falar número',
                    count($digitos),
                    $sons,
                    $sons,
                    $idioma
                )
        );
    }

    // ---------------------------------------------------------------
    /**
     * Todo destino que a tela oferece tem de virar dialplan.
     *
     * A tela e o resolvedor cresceram separados: o seletor oferecia oito
     * grupos e o resolvedor entendia onze, com DISA carregada e nunca
     * mostrada. Um destino que a tela mostra e o gerador não conhece vira
     * "NoOp(Destino não configurado)" — a chamada cai e ninguém sabe por quê.
     */
    private function destinos(): void
    {
        $d = new Destino(
            ['1001' => ['numero' => '1001', 'tempo_toque' => 20, 'voicemail' => 1, 'opcoes_dial' => '']],
            [7 => ['nome' => 'Hora certa', 'contexto' => 'telium-recursos',
                   'extensao' => '*60', 'prioridade' => 1]]
        );

        $esperado = [
            'ramal'         => ['1001', 'GoSub(sub-ramal'],
            'fila'          => ['3000', 'Goto(telium-filas,3000,1)'],
            'ura'           => ['1',    'Goto(telium-ura-1,s,1)'],
            'grupo'         => ['2000', 'Goto(telium-grupos,2000,1)'],
            'voicemail'     => ['1001', 'VoiceMail(1001@telium,u)'],
            'anuncio'       => ['3',    'Goto(telium-anuncios,3,1)'],
            'condicao'      => ['2',    'Goto(telium-condicoes,2,1)'],
            'conferencia'   => ['4000', 'Goto(telium-conferencias,4000,1)'],
            'paging'        => ['5000', 'Goto(telium-paging,5000,1)'],
            'disa'          => ['1',    'Goto(telium-disa,disa-1,1)'],
            'externo'       => ['011999', 'Goto(telium-saida,011999,1)'],
            'personalizado' => ['7',    'Goto(telium-recursos,*60,1)'],
            'desligar'      => ['',     'Hangup()'],
            'ocupado'       => ['',     'Busy(20)'],
            'congestionado' => ['',     'Congestion(20)'],
        ];

        foreach ($esperado as $tipo => [$valor, $trecho]) {
            $linhas = implode(' | ', $d->linhas($tipo, $valor));
            $this->ok(
                str_contains($linhas, $trecho),
                sprintf('destino "%s" vira %s', $tipo, $trecho)
            );
        }

        $this->ok(
            str_contains(implode(' ', $d->linhas('inventado', '1')), 'não configurado'),
            'destino desconhecido não some em silêncio'
        );

        // Cada tipo precisa de uma frase para a linha de comentário do
        // dialplan e para a tela de conferência do cadastro.
        $semDescricao = [];
        foreach (array_keys($esperado) as $tipo) {
            if ($d->descricao($tipo, '1') === 'não configurado') {
                $semDescricao[] = $tipo;
            }
        }
        $this->ok($semDescricao === [], 'todo destino sabe se descrever: ' . (implode(',', $semDescricao) ?: 'ok'));
    }

    // ---------------------------------------------------------------
    /**
     * Ler a lista para escolher um destino não é administrar a lista.
     *
     * A tela de URA precisa listar ramais para apontar uma tecla. Exigir
     * dela o módulo "Ramais" deixava 19 das 63 telas quebradas para todo
     * perfil que não fosse o administrador: a tela abria e cada chamada
     * dela voltava 403. O que não pode voltar é o contrário — quem só
     * escolhe destino não grava, não exclui e não abre o cadastro.
     */
    private function permissaoReferencia(): void
    {
        $so = static fn (string $modulo): array => [$modulo];

        $mw = new Permissao('conn.ramais', null, ['apps.ura']);
        $this->ok(
            $this->passou($mw, $so('apps.ura')),
            'quem tem URA consegue listar ramais para montar o seletor'
        );
        $this->ok(
            $this->passou($mw, $so('conn.ramais')),
            'o dono do módulo continua passando'
        );
        $this->ok(
            !$this->passou($mw, $so('rel.cdr')),
            'módulo que não referencia a lista continua barrado'
        );

        // Sem a lista de alternativas, nada muda para quem não é dono.
        $estrito = new Permissao('conn.ramais');
        $this->ok(
            !$this->passou($estrito, $so('apps.ura')),
            'a rota de gravar e a de abrir o cadastro seguem só com o dono'
        );
    }

    /** Roda o middleware com um perfil e diz se ele deixou passar. */
    private function passou(Permissao $mw, array $allow): bool
    {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/ramais')
            ->withAttribute('allow', $allow)
            ->withAttribute('caps', ['ver', 'criar', 'editar', 'excluir']);

        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                return new \Slim\Psr7\Response(200);
            }
        };

        return $mw->process($req, $handler)->getStatusCode() === 200;
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

        // external_media_address conserta o "c=" do SDP e só: a lista de
        // candidatos ICE é montada à parte e, atrás de NAT, sai só com o
        // endereço interno. O navegador não alcança, o ICE não fecha, e a
        // chamada conecta sem áudio mesmo com o navegador oferecendo um
        // candidato público válido. O mapa do rtp.conf conserta isso.
        $gerado = (new GeradorTransportes())->gerar();
        $this->ok(
            isset($gerado['rtp.ice.conf']),
            'o mapa de candidatos ICE é gerado junto com os transportes'
        );
        $ice = $gerado['rtp.ice.conf'] ?? '';
        $publico = Rede::ipPublico();
        $local   = Rede::enderecoLocal();
        $temMapa = str_contains($ice, '[ice_host_candidates]');

        // Mapear só faz sentido quando os dois endereços são diferentes.
        // Servidor com o IP público na própria interface já anuncia
        // candidato alcançável, e o arquivo sai vazio com razão — a
        // primeira versão deste teste exigia o mapa mesmo assim e
        // reprovou uma instalação correta na máquina do cliente.
        $precisaMapear = $publico !== '' && $local !== '' && $local !== $publico;

        $this->ok(
            $precisaMapear ? ($temMapa && str_contains($ice, $publico)) : !$temMapa,
            $precisaMapear
                ? "o mapa leva {$local} para {$publico}"
                : sprintf(
                    'sem nada a mapear, o arquivo fica vazio (local %s, público %s)',
                    $local ?: 'não descoberto',
                    $publico ?: 'não configurado'
                )
        );

        // O navegador não roda no servidor: ele precisa resolver e
        // alcançar o STUN e o TURN. Apontar os dois para o nome interno
        // da central deixa o navegador sem candidato srflx e sem relay —
        // a chamada conecta, não passa áudio, e a única mensagem é a do
        // próprio navegador: "ICE failed, your TURN server appears to be
        // broken". Foi assim que aconteceu no POC.
        foreach ([
            'stun:pabx.telium.local:3478'   => '.local não vale para o navegador de fora',
            'turn:192.168.1.10:3478'        => 'endereço de rede interna não serve de ICE',
            'stun:naoexiste.invalido:3478'  => 'nome que não resolve não serve de ICE',
            ''                              => 'endereço vazio não passa por servidor válido',
        ] as $servidor => $porque) {
            $this->ok(!Rede::conferirServidorIce($servidor)['ok'], $porque);
        }
        $this->ok(
            Rede::conferirServidorIce('stun:stun.l.google.com:19302')['ok'],
            'um STUN público de verdade passa'
        );
        $this->ok(
            Rede::conferirServidorIce('turn:200.170.198.144:3478?transport=tcp')['ok'],
            'TURN em IP público passa, com parâmetro e tudo'
        );

        // O console troca o host inalcançável pelo endereço público ao
        // entregar a lista ao navegador — mas só o que é impossível por
        // construção. Um TURN de terceiros que não resolve daqui pode
        // resolver lá, e apontá-lo para o nosso servidor mandaria o
        // navegador para um relay que não conhece a credencial dele.
        $publicoAntes = Rede::ipPublico();
        Rede::guardar('200.170.198.144', implode(',', Rede::redesLocais()));
        foreach ([
            'turn:pabx.telium.local:3478'                => 'turn:200.170.198.144:3478',
            'turns:pabx.telium.local:5349?transport=tcp' => 'turns:200.170.198.144:5349?transport=tcp',
            'turn:192.168.1.10:3478'                     => 'turn:200.170.198.144:3478',
            'stun:stun.l.google.com:19302'               => 'stun:stun.l.google.com:19302',
            'turn:turn.de-terceiros.invalido:3478'       => 'turn:turn.de-terceiros.invalido:3478',
        ] as $antes => $depois) {
            $this->ok(
                Rede::corrigirServidorIce($antes) === $depois,
                sprintf('%s vira %s', $antes, $depois === $antes ? 'ele mesmo' : $depois)
            );
        }
        Rede::guardar($publicoAntes, implode(',', Rede::redesLocais()));
        $this->ok(
            Rede::corrigirServidorIce('turn:pabx.telium.local:3478') === 'turn:pabx.telium.local:3478'
                || Rede::ipPublico() !== '',
            'sem endereço público configurado, nada é reescrito — fica o aviso na tela'
        );

        // 100.64.0.0/10 é o CGNAT da RFC 6598 — onde fica quem está atrás
        // do NAT da operadora. O filtro do PHP não cobre essa faixa, e sem
        // ela um servidor em CGNAT passava por "tem IP público": o
        // diagnóstico dizia que estava tudo certo enquanto o SDP saía com
        // um endereço que ninguém alcança e a chamada ficava sem som.
        foreach (['192.168.1.1', '10.0.0.5', '172.16.3.9', '127.0.0.1',
                  '100.64.0.1', '100.64.50.3', '100.127.255.254'] as $ip) {
            $this->ok(Rede::ehPrivado($ip), "{$ip} é endereço que não chega de fora");
        }
        foreach (['200.170.198.144', '8.8.8.8', '100.63.255.255', '100.128.0.1'] as $ip) {
            $this->ok(!Rede::ehPrivado($ip), "{$ip} é endereço público");
        }

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

        // O Asterisk escreve "enbabled" mesmo; casar pelas duas grafias
        // evita que uma correção futura apague o aviso sem ninguém ver.
        $ligado = "  STUN:            enbabled\n   Address:        127.0.1.1:3478";
        $this->ok(
            Diagnostico::stunDoRtp($ligado) === ['ativo' => true, 'endereco' => '127.0.1.1:3478'],
            'vê o STUN bloqueante ligado no rtp.conf'
        );
        $this->ok(
            Diagnostico::stunDoRtp("  STUN:            enabled\n   Address:        1.2.3.4:3478")['ativo'],
            'e também na grafia certa, se um dia for corrigida'
        );
        $this->ok(
            !Diagnostico::stunDoRtp('  STUN:            disabled')['ativo'],
            'STUN desligado não vira aviso'
        );

        // Ramal marcado como WebRTC registrado por UDP: a chamada
        // conecta, o ICE nunca fecha e cada envio de RTP devolve zero
        // byte. É o "conecta e fica mudo" mais difícil de diagnosticar,
        // porque não há erro nenhum no log — só "len -000012" no rtp
        // debug, que ninguém liga.
        $contatosRamais = "  Contact:  1000/sip:abc@127.0.0.1:33278;transport=WS   h Avail 15\n"
                        . "  Contact:  1001/sip:1001@200.170.201.2:55020          h Avail 21\n"
                        . "  Contact:  1002/sip:1002@10.0.0.5:5060;transport=tls  h Avail 8";
        $this->ok(
            Diagnostico::transporteDoContato($contatosRamais, '1000') === 'WS',
            'lê o ramal registrado pelo navegador'
        );
        $this->ok(
            Diagnostico::transporteDoContato($contatosRamais, '1001') === 'udp',
            'contato sem parâmetro de transporte é UDP, que é o padrão do SIP'
        );
        $this->ok(
            Diagnostico::transporteDoContato($contatosRamais, '1002') === 'tls',
            'lê o transporte declarado no contato'
        );
        $this->ok(
            Diagnostico::transporteDoContato($contatosRamais, '1003') === '',
            'ramal sem contato não inventa transporte'
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

        // A trilha de auditoria diz quem mexeu em quê, com IP e horário.
        // Sob a chave antiga ("rel.logs") ela caía dentro do curinga
        // "rel.*" que supervisor e auditor têm — dois perfis liam o
        // registro das próprias ações. Agora é módulo do administrador.
        $this->ok(
            !Permissoes::podeModulo(['rel.*'], 'admin.auditoria'),
            'a auditoria não é alcançada pelo curinga de relatórios'
        );
        $this->ok(
            Permissoes::podeModulo(['*'], 'admin.auditoria'),
            'o administrador alcança a auditoria'
        );

        // O operador tem "pcu.*", que resolve o ramal pela sessão. As
        // telas apps.correiovoz e apps.sigame são as de administração e
        // listam TODOS os ramais, com senha de caixa postal e desvio
        // editáveis: não podem estar no alcance de um atendente.
        foreach (['apps.correiovoz', 'apps.sigame'] as $modulo) {
            $this->ok(
                !Permissoes::podeModulo(['dash.visaogeral', 'pcu.*'], $modulo),
                "o operador não alcança {$modulo}, que lista todos os ramais"
            );
        }
        $this->ok(
            Permissoes::podeModulo(['pcu.*'], 'pcu.correiovoz'),
            'o operador alcança o próprio correio de voz'
        );
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


    /**
     * O que o softphone do navegador precisa para ter áudio.
     *
     * Os dois casos aqui nasceram de defeitos que só apareciam como
     * "a chamada conecta e ninguém ouve", sem erro em lugar nenhum.
     */
    private function webrtc(): void
    {
        // 1) O transporte wss preso no loopback prendia JUNTO o soquete
        // de RTP: o Asterisk anunciava o endereço da placa no SDP, mas
        // não conseguia responder a um único teste de conectividade do
        // ICE. O ouvinte do WebSocket continua sendo o do http.conf, em
        // 127.0.0.1 — este bind não abre porta nenhuma.
        $transportes = (new GeradorTransportes())->gerar()['pjsip.transports.conf'];
        $achado = [];
        $tem = preg_match('/\[transport-wss\][^\[]*?bind\s*=\s*(\S+)/s', $transportes, $achado) === 1;
        $endereco = $tem ? explode(':', (string) $achado[1])[0] : '';

        $this->ok($tem, 'o transporte wss é gerado');
        $this->ok(
            $tem && $endereco !== '127.0.0.1' && !str_starts_with($endereco, '127.'),
            'o transporte wss não fica no loopback — com 127.0.0.1 o RTP nasce lá e o '
            . "áudio do navegador nunca passa (está \"{$endereco}\")"
        );

        // 1) O telefone pelo navegador está ligado? A resposta manda em
        // tudo o que vem abaixo, e ela mora no banco — o console troca
        // com um clique, sem reinstalar nada.
        $ligado = Rede::softphoneNoNavegador();
        $guardado = Bd::valor("SELECT valor FROM sistema WHERE chave = 'webrtc_ativo'");
        if (is_string($guardado)) {
            $this->ok(
                $ligado === ($guardado === '1'),
                sprintf('o estado do telefone pelo navegador confere com o banco (está %s)',
                    $ligado ? 'ligado' : 'desligado')
            );
        }

        // 1a) O veredito de cada chamada do navegador. É a frase que o
        // suporte e o cliente leem sobre a MESMA chamada, então ela não
        // pode depender de quem está olhando.
        foreach ([
            // entrada, saída, caminho, perdidos => veredito
            [500, 500, 'host',  0,   'ok'],
            [0,   0,   null,    0,   'nao_fechou'],
            [0,   0,   'relay', 0,   'mudo'],
            [0,   800, 'srflx', 0,   'so_falou'],
            [800, 0,   'relay', 0,   'so_ouviu'],
            [100, 100, 'host',  400, 'instavel'],
            [100, 100, 'host',  2,   'ok'],
        ] as [$entrada, $saida, $caminho, $perdidos, $esperado]) {
            $obtido = Portal::veredito($entrada, $saida, $caminho, $perdidos);
            $this->ok(
                $obtido === $esperado,
                sprintf('chamada com %d de entrada e %d de saída (%s, %d perdidos) é "%s"%s',
                    $entrada, $saida, $caminho ?? 'sem caminho', $perdidos, $esperado,
                    $obtido === $esperado ? '' : " — devolveu \"{$obtido}\"")
            );
        }

        // 1b) Música em espera. O MOH-OPSOUND é pedido ao menuselect com
        // "falha aqui não interrompe": sem saída para a internet na hora
        // de compilar, o diretório fica vazio, a classe [default]
        // continua existindo apontando para nada, e quem espera na fila
        // ouve silêncio — sem um erro sequer.
        $moh = '/var/lib/asterisk/moh';
        if (!is_dir($moh)) {
            echo "    \033[33m!\033[0m {$moh} não existe nesta máquina — "
               . "música em espera conferida só no servidor\n";
        } else {
            $tocaveis = glob($moh . '/*.{wav,gsm,ulaw,alaw,sln,g722,WAV}', GLOB_BRACE) ?: [];
            $this->ok(
                $tocaveis !== [],
                sprintf('a música em espera padrão está instalada (%d arquivo(s) em %s) — '
                      . 'sem ela a fila toca silêncio', count($tocaveis), $moh)
            );
        }

        // 2) O JsSIP segue a gramática do RFC 3261: nome de máquina
        // começa por letra. Com um domínio que ele não consegue
        // analisar, o navegador DESCARTA em silêncio o OPTIONS, o
        // NOTIFY e o INVITE que a central manda — o contato vira
        // inalcançável e nenhuma chamada entra no ramal.
        foreach ([
            'pabx.cliente.com.br' => true,
            'pabx'                => true,
            'pabx-01.local'       => true,
            '200.170.198.144'     => true,
            '9195cd758909'        => false,   // hostname de contêiner: começa por dígito
            // Só o último rótulo precisa começar por letra — conferido
            // contra a gramática do próprio JsSIP, no navegador.
            '4pabx.cliente.com'   => true,
            'a.b.9'               => false,
            ''                    => false,
            'com ponto e vírgula' => false,
        ] as $host => $vale) {
            $this->ok(
                Rede::dominioValidoNoNavegador((string) $host) === $vale,
                sprintf('"%s" %s domínio que o navegador consegue analisar',
                    $host === '' ? '(vazio)' : $host, $vale ? 'é' : 'não é')
            );
        }
    }

    /**
     * Cada ramal WebRTC, do jeito que ele saiu no arquivo.
     *
     * Uma opção incoerente aqui não dá erro no Asterisk: a chamada é
     * aceita, o cronômetro corre e ninguém ouve nada. É o pior modo de
     * falhar, e é por isso que a conferência é sobre o arquivo gerado e
     * não sobre o cadastro.
     */
    private function webrtcGerado(): void
    {
        $arquivo = (new GeradorPjsip())->gerar()['pjsip.endpoints.conf'];
        $ramais = Bd::todos('SELECT numero FROM ramais WHERE ativo = 1 AND webrtc = 1 ORDER BY numero');

        if ($ramais === []) {
            $this->ok(true, 'nenhum ramal WebRTC cadastrado — nada a conferir');

            return;
        }

        $dominio = Rede::dominioSip();
        $navegador = ['opus', 'g722', 'ulaw', 'alaw'];

        foreach ($ramais as $r) {
            $n = (string) $r['numero'];
            $bloco = $this->blocoDoEndpoint($arquivo, $n);

            if ($bloco === '') {
                $this->ok(false, "ramal {$n}: o endpoint não foi gerado");
                continue;
            }

            $tem = static fn (string $opcao, string $valor): bool
                => preg_match('/^\s*' . preg_quote($opcao, '/') . '\s*=\s*' . preg_quote($valor, '/') . '\s*$/mi', $bloco) === 1;

            foreach ([
                'media_encryption' => 'dtls',
                'use_avpf'         => 'yes',
                'ice_support'      => 'yes',
                'rtcp_mux'         => 'yes',
                // O navegador apresenta certificado autoassinado: a
                // confiança vem da impressão digital do SDP. Exigir o
                // certificado derruba o DTLS e a chamada fica muda.
                'dtls_verify'      => 'fingerprint',
                'dtls_setup'       => 'actpass',
                // Mídia direta obrigaria a outra ponta a falar DTLS-SRTP
                // e fechar ICE com o navegador.
                'direct_media'     => 'no',
            ] as $opcao => $valor) {
                $this->ok($tem($opcao, $valor), "ramal {$n}: {$opcao} = {$valor}");
            }

            $codecs = [];
            preg_match('/^\s*allow\s*=\s*(.+)$/mi', $bloco, $codecs);
            $lista = Conferencia::listaCodecs((string) ($codecs[1] ?? ''));
            $this->ok(
                array_intersect($lista, $navegador) !== [],
                sprintf('ramal %s: tem codec que o navegador fala (tem "%s")', $n, implode(',', $lista))
            );

            if ($dominio !== '') {
                $this->ok(
                    $tem('from_domain', $dominio),
                    "ramal {$n}: from_domain = {$dominio} — sem isso a central assina com o "
                    . 'hostname da máquina e o navegador descarta a chamada'
                );
            }
        }
    }


    /**
     * O telefone consegue buscar a própria configuração?
     *
     * Instalar trinta ramais era configurar trinta aparelhos à mão. O
     * cadastro existia e ninguém lia: faltava o endereço que o aparelho
     * consulta no boot. É uma porta SEM sessão que entrega senha SIP —
     * por isso cada conferência aqui é sobre o que a protege.
     */
    private function provisionamento(): void
    {
        // O nome do arquivo é montado pelo próprio aparelho, e cada
        // fabricante monta o seu.
        foreach ([
            ['001122334455.cfg',    '001122334455'],
            ['cfg001122334455.xml', '001122334455'],
            ['CFGAABBCCDDEEFF.XML', 'aabbccddeeff'],
            ['00:11:22:33:44:55',   '001122334455'],
            ['AA-BB-CC-DD-EE-FF',   'aabbccddeeff'],
        ] as [$arquivo, $esperado]) {
            $obtido = Provisionamento::macDoArquivo($arquivo);
            $this->ok(
                $obtido === $esperado,
                sprintf('"%s" é o aparelho %s%s', $arquivo, $esperado,
                    $obtido === $esperado ? '' : " — leu \"{$obtido}\"")
            );
        }

        // "cfg" tem dois dígitos hexadecimais dentro, e limpar só o que
        // não é hexadecimal deslocava o MAC inteiro: todo aparelho
        // Grandstream ficava desconhecido. O caso existe por isso.
        $this->ok(
            Provisionamento::macDoArquivo('cfgaabbccddeeff.xml') === 'aabbccddeeff',
            'o "cfg" da Grandstream não é confundido com dígitos do MAC'
        );

        foreach ([
            ['001122334455', true],
            ['00112233445',  false],
            ['00112233445g', false],
            ['',             false],
        ] as [$mac, $vale]) {
            $this->ok(
                Provisionamento::macValido($mac) === $vale,
                sprintf('"%s" %s um MAC', $mac === '' ? '(vazio)' : $mac, $vale ? 'é' : 'não é')
            );
        }

        // A trava de rede é o que impede a senha SIP de sair pela
        // internet para quem adivinhar um MAC.
        foreach ([
            ['192.168.1.50', true],
            ['10.20.30.40',  true],
            ['127.0.0.1',    true],
            ['8.8.8.8',      false],
            ['200.170.198.144', false],
            ['',             false],
        ] as [$ip, $interno]) {
            $this->ok(
                Provisionamento::daRedeLocal($ip) === $interno,
                sprintf('%s %s da rede interna', $ip === '' ? '(vazio)' : $ip, $interno ? 'é' : 'não é')
            );
        }

        // O arquivo precisa sair com a senha certa e sem quebra de linha
        // injetada pelo nome do ramal, que é campo digitado.
        $dispositivo = ['mac' => '00:11:22:33:44:55', 'fabricante' => 'yealink'];
        $ramal = ['numero' => '1001', 'senha_sip' => 'S3nh4Forte!', 'nome' => "Recepção\nmalicioso = 1"];

        $cfg = Provisionamento::gerar($dispositivo, $ramal);
        $this->ok(str_contains($cfg, 'account.1.password = S3nh4Forte!'), 'o arquivo leva a senha SIP do ramal');
        $this->ok(str_contains($cfg, 'account.1.user_name = 1001'), 'o arquivo leva o número do ramal');
        $this->ok(
            !str_contains($cfg, "\nmalicioso = 1"),
            'quebra de linha no nome do ramal não vira linha de configuração no aparelho'
        );

        $xml = Provisionamento::gerar(['mac' => 'aabbccddeeff', 'fabricante' => 'grandstream'], $ramal);
        $this->ok(str_contains($xml, '<P34>S3nh4Forte!</P34>'), 'a Grandstream recebe a senha no P34');
        $this->ok(str_starts_with(trim($xml), '<?xml'), 'o arquivo da Grandstream é XML');

        // O segredo do caminho não pode nascer vazio: vazio, a porta
        // ficaria aberta para qualquer um que soubesse um MAC.
        $this->ok(
            strlen(Provisionamento::segredo()) >= 16,
            'o segredo do endereço de provisionamento tem tamanho de segredo'
        );
    }

    /**
     * A conta de cada chamada.
     *
     * Esta tela somava cdr.custo desde sempre, e nada escrevia a coluna:
     * R$ 0,00 para todo mês, sem erro nenhum. A matemática não é
     * "minutos vezes preço" — a operadora cobra em frações, e um total
     * que não bate com a fatura é pior do que nenhum total.
     */
    private function tarifacao(): void
    {
        // Frações, do jeito que a operadora cobra. Mínimo de 30 s e
        // depois de 6 em 6: 5 s custam 30 s, 31 s custam 36 s.
        $celular = ['custo_minuto' => 0.35, 'taxa_fixa' => 0,
                    'primeiro_incremento_seg' => 30, 'incremento_seg' => 6];

        foreach ([
            [0,   0.0,    'chamada não atendida não custa nada'],
            [5,   0.175,  'cinco segundos custam o mínimo de trinta'],
            [30,  0.175,  'trinta segundos custam trinta'],
            [31,  0.21,   'trinta e um segundos passam para a fração seguinte'],
            [60,  0.35,   'um minuto custa um minuto'],
        ] as [$billsec, $esperado, $porque]) {
            $obtido = Tarifa::custo($celular, $billsec);
            $this->ok(
                abs($obtido - $esperado) < 0.00005,
                sprintf('%s (%ds = R$ %.4f)%s', $porque, $billsec, $esperado,
                    abs($obtido - $esperado) < 0.00005 ? '' : sprintf(' — deu R$ %.4f', $obtido))
            );
        }

        // Minuto cheio e por segundo saem do mesmo par de campos.
        $this->ok(
            abs(Tarifa::custo(['custo_minuto' => 1, 'primeiro_incremento_seg' => 0,
                               'incremento_seg' => 60], 61) - 2.0) < 0.00005,
            'com incremento de 60 s, 61 segundos custam dois minutos'
        );

        // A gramática dos padrões é a mesma das rotas de saída.
        foreach ([
            ['_0800.',        '08007771234', true],
            ['_0800.',        '32651234',    false],
            ['_0XX9XXXXXXXX', '0XX911999998888', false],
            ['_11N',          '119',         true],
            ['_11N',          '111',         false],
            ['_[147]X',       '41',          true],
            ['_[147]X',       '21',          false],
            ['3265',          '32651234',    false],
            ['3265',          '3265',        true],
        ] as [$padrao, $numero, $esperado]) {
            $this->ok(
                Tarifa::casa($padrao, $numero) === $esperado,
                sprintf('"%s" %s com %s', $padrao, $esperado ? 'casa' : 'não casa', $numero)
            );
        }

        // A escolha: com padrão vence a da classe inteira. É o que faz
        // "0800 é grátis" ganhar de "local custa X" sem depender de
        // quem foi cadastrado primeiro.
        $tarifas = [
            ['id' => 1, 'classe' => 'local',    'padrao' => null,     'ordem' => 30, 'ativo' => 1],
            ['id' => 2, 'classe' => 'qualquer', 'padrao' => '_0800.', 'ordem' => 20, 'ativo' => 1],
            ['id' => 3, 'classe' => 'celular',  'padrao' => null,     'ordem' => 40, 'ativo' => 0],
        ];
        $this->ok(
            (Tarifa::escolher($tarifas, 'local', '08007771234')['id'] ?? 0) === 2,
            'o 0800 pega a tarifa do padrão, e não a da classe local'
        );
        $this->ok(
            (Tarifa::escolher($tarifas, 'local', '32651234')['id'] ?? 0) === 1,
            'destino comum pega a tarifa da classe'
        );
        $this->ok(
            Tarifa::escolher($tarifas, 'celular', '11999998888') === null,
            'tarifa desativada não é escolhida'
        );
        $this->ok(
            Tarifa::escolher($tarifas, null, '11999998888') === null,
            'chamada sem classe não pega tarifa de classe nenhuma'
        );

        // O dialplan precisa gravar a classe, senão nada disso se aplica.
        $dialplan = (new GeradorDialplan())->gerar();
        $saida = $dialplan['extensions.saida.conf'] ?? '';
        if (str_contains($saida, 'Rota de saída')) {
            $this->ok(
                str_contains($saida, 'Set(CDR(classe)='),
                'a rota de saída grava a classe no CDR — é ela que decide a tarifa'
            );
        }
    }

    /**
     * O backup consegue sair do servidor?
     *
     * Backup guardado só na máquina que ele deveria salvar não é
     * backup. São duas saídas: baixar pelo console e mandar para um
     * destino remoto — e a segunda depende do curl do PHP, que é fácil
     * de faltar numa instalação enxuta.
     */
    private function backup(): void
    {
        $this->ok(
            Bd::um('SELECT id FROM backup_destino_remoto WHERE id = 1') !== null,
            'a linha de destino remoto existe — sem ela a tela de backup não abre a configuração'
        );

        $d = BackupRemoto::destino();
        $this->ok(
            !BackupRemoto::utilizavel(['ativo' => 1, 'host' => '', 'usuario' => 'x']),
            'destino sem endereço não é considerado utilizável'
        );
        $this->ok(
            !BackupRemoto::utilizavel(['ativo' => 0, 'host' => 'h', 'usuario' => 'u']),
            'destino desligado não é usado mesmo preenchido'
        );

        // Só cobra o curl de quem realmente ligou o envio: numa central
        // que guarda o backup localmente, a extensão não faz falta.
        if (BackupRemoto::utilizavel($d)) {
            $this->ok(
                function_exists('curl_init'),
                'o PHP tem a extensão curl — é ela que fala FTP, e o destino remoto está ligado'
            );
        }
    }

    /**
     * As faixas de horário, do jeito que saem no GotoIfTime.
     *
     * O campo de dia da semana aceita lista, mas com "&" — a vírgula é
     * o que separa os QUATRO campos do GotoIfTime. Escrever "mon,tue"
     * empurra tudo uma casa: "tue" vira dia do mês, "wed" vira mês, e a
     * condição deixa de valer em qualquer dia sem um aviso sequer.
     * Conferido no Asterisk 22 com chamada de verdade.
     */
    private function horarios(): void
    {
        $faixas = Bd::todos('SELECT * FROM grupo_horario_faixas');
        if ($faixas === []) {
            $this->ok(true, 'nenhuma faixa de horário cadastrada — nada a conferir');

            return;
        }

        $dialplan = (new GeradorDialplan())->gerar();
        $texto = $dialplan['extensions.condicoes.conf'] ?? '';

        $linhas = [];
        preg_match_all('/GotoIfTime\(([^?]*)\?/', $texto, $linhas);

        foreach ($linhas[1] ?? [] as $regra) {
            $campos = explode(',', $regra);
            $this->ok(
                count($campos) === 4,
                sprintf('GotoIfTime(%s) tem os quatro campos — lista de dias usa "&", não vírgula', $regra)
            );
        }

        // A lista com vírgula nunca pode chegar ao arquivo.
        $this->ok(
            !preg_match('/GotoIfTime\([^?]*,(sun|mon|tue|wed|thu|fri|sat),(sun|mon|tue|wed|thu|fri|sat)/', $texto),
            'nenhuma regra de horário separa dias por vírgula'
        );
    }

    /**
     * O caminho do certificado até os serviços.
     *
     * Enviar um certificado e ele não chegar em algum serviço é a falha
     * mais silenciosa deste módulo: a tela diz "aplicado", o serviço
     * segue com o autoassinado de fábrica e só o navegador de quem tenta
     * usar reclama — no caso do TURN, recusando "turns:" e deixando a
     * chamada sem caminho de áudio.
     */
    private function certificados(): void
    {
        // O coturn tem escuta TLS e lê o próprio par. Sem esta entrada,
        // o certificado enviado pelo console nunca chegava nele.
        $this->ok(
            in_array('turn', Certificado::SERVICOS, true),
            'o TURN está entre os serviços que recebem certificado'
        );

        // Uma linha por serviço, sempre: é dela que a tela lê o estado.
        // Serviço na constante e ausente do banco = migração que não
        // rodou, e a atribuição some sem erro.
        $noBanco = array_column(Bd::todos('SELECT servico FROM certificado_servicos'), 'servico');
        foreach (Certificado::SERVICOS as $servico) {
            $this->ok(
                in_array($servico, $noBanco, true),
                "o serviço \"{$servico}\" tem linha em certificado_servicos"
            );
        }

        // O aplicador roda como root e é publicado pelo instalador. Sem
        // ele — ou sem a regra de sudo — a atribuição fica "pendente"
        // para sempre, e antes disto o console respondia "aplicando".
        $script = '/usr/local/sbin/telium-certificados';
        if (!is_file($script)) {
            echo "    \033[33m!\033[0m o aplicador não está nesta máquina "
               . "({$script}) — grupo conferido só no servidor\n";

            return;
        }

        $saida = [];
        $rc = 0;
        @exec('sudo -n -l ' . escapeshellarg($script) . ' 2>&1', $saida, $rc);
        $podeChamar = $rc === 0;
        $this->ok(
            $podeChamar,
            'o console pode acionar o aplicador por sudo — sem a regra em '
            . '/etc/sudoers.d/telium-certificados, aplicar certificado não faz nada'
        );

        if (!$podeChamar) {
            return;     // sem sudo não há como perguntar mais nada a ele
        }

        // Quais serviços ele sabe instalar. A pergunta vai para o
        // PRÓPRIO programa, e não para o texto do arquivo: ele é
        // 0750 root:root de propósito, e esta bateria roda como o
        // usuário da API — ler o arquivo daqui devolve string vazia e
        // reprova tudo, que foi como este teste nasceu errado.
        $lista = [];
        $rc = 0;
        @exec('sudo -n ' . escapeshellarg($script) . ' servicos 2>/dev/null', $lista, $rc);
        $suportados = $rc === 0
            ? array_values(array_filter(array_map('trim', $lista)))
            : [];

        foreach (Certificado::SERVICOS as $servico) {
            $this->ok(
                in_array($servico, $suportados, true),
                sprintf('o aplicador sabe instalar o certificado do serviço "%s"%s',
                    $servico,
                    $suportados === [] ? ' — ele não respondeu quais conhece' : '')
            );
        }
    }

    /** O trecho do arquivo entre [numero] type=endpoint e o próximo [. */
    private function blocoDoEndpoint(string $arquivo, string $numero): string
    {
        $achado = [];
        $ok = preg_match(
            '/^\[' . preg_quote($numero, '/') . '\]\s*\n(?=(?:[^\[]*?type\s*=\s*endpoint))([^\[]*)/m',
            $arquivo,
            $achado
        ) === 1;

        return $ok ? (string) $achado[1] : '';
    }

    /**
     * A única porta sem sessão que entrega senha: ela recusa?
     *
     * O teste de rotas a deixa passar de propósito — um telefone de mesa
     * não faz login. O que sobra é conferir aqui, com o aplicativo de
     * verdade, que ela não abre para quem não tem o segredo.
     */
    private function portaDoProvisionamento(): void
    {
        $app = $this->aplicativo();
        if ($app === null) {
            return;
        }

        $pedir = static function (string $caminho) use ($app): int {
            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', $caminho);

            try {
                return $app->handle($req)->getStatusCode();
            } catch (\Throwable) {
                return 0;
            }
        };

        $this->ok(
            $pedir('/prov/segredo-errado/001122334455.cfg') === 404,
            'o provisionamento recusa quem não tem o segredo do endereço'
        );
        $this->ok(
            $pedir('/prov/' . Provisionamento::segredo() . '/aaaaaaaaaaaa.cfg') === 404,
            'o provisionamento recusa um MAC que não está cadastrado'
        );
    }

    /**
     * O aplicativo da API, montado uma vez só.
     *
     * O index.php define constantes no topo. Carregá-lo duas vezes — um
     * grupo de teste para as rotas, outro para a porta pública — rende
     * "Constant already defined" no meio da bateria, que assusta quem lê
     * a saída e não é defeito nenhum.
     */
    private ?\Slim\App $app = null;

    private function aplicativo(): ?\Slim\App
    {
        if ($this->app !== null) {
            return $this->app;
        }

        $indice = __DIR__ . '/../../public/index.php';
        if (!is_file($indice)) {
            return null;
        }

        if (!defined('TELIUM_SEM_RUN')) {
            define('TELIUM_SEM_RUN', true);
        }

        $app = require $indice;

        return $this->app = $app instanceof \Slim\App ? $app : null;
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
     * Os perfis de fábrica, do jeito que estão no banco.
     *
     * A conferência acima é sobre a regra; esta é sobre os dados. Um
     * módulo a mais num perfil é uma porta aberta que ninguém vê, porque
     * ela aparece como um item de menu comum.
     */
    private function perfisDeFabrica(): void
    {
        $modulos = static function (string $chave): array {
            $linhas = Bd::todos(
                'SELECT pm.modulo FROM perfil_modulos pm
                   JOIN perfis p ON p.id = pm.perfil_id
                  WHERE p.chave = ?',
                [$chave]
            );

            return array_column($linhas, 'modulo');
        };

        foreach (['supervisor', 'operador', 'auditor'] as $perfil) {
            $lista = $modulos($perfil);
            if ($lista === []) {
                continue;       // perfil apagado pelo cliente: escolha dele
            }

            $this->ok(
                !Permissoes::podeModulo($lista, 'admin.auditoria'),
                "o perfil {$perfil} não alcança a auditoria"
            );
        }

        $operador = $modulos('operador');
        if ($operador !== []) {
            foreach (['apps.correiovoz', 'apps.sigame'] as $modulo) {
                $this->ok(
                    !Permissoes::podeModulo($operador, $modulo),
                    "o perfil operador não alcança {$modulo} — ela lista todos os ramais"
                );
            }
        }
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

        $app = $this->aplicativo();
        if ($app === null) {
            $this->ok(false, 'não foi possível montar o aplicativo da API');

            return;
        }

        // As três portas sem sessão, e cada uma por um motivo. O
        // provisionamento é a mais delicada: entrega senha SIP, e quem
        // busca é um telefone de mesa, que não tem como fazer login. O
        // que a protege é conferido logo abaixo, caso a caso.
        // A chave é montada trocando cada {parâmetro} por "1", então é
        // assim que a rota de provisionamento aparece aqui.
        $publicas = ['GET /api/health', 'POST /api/auth/login', 'GET /api/prov/1/1'];
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
