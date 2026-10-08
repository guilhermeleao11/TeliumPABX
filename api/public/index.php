<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Telium\Http\Controllers\Autenticacao as CtrlAuth;
use Telium\Http\Controllers\Cadastros;
use Telium\Http\Controllers\RamaisCsv;
use Telium\Http\Controllers\Pesquisas as CtrlPesquisas;
use Telium\Http\Controllers\Certificados;
use Telium\Http\Controllers\Provisionar;
use Telium\Http\Controllers\CallCenter as CtrlCallCenter;
use Telium\Http\Controllers\Conferencias;
use Telium\Http\Controllers\Contatos;
use Telium\Http\Controllers\Destinos;
use Telium\Http\Controllers\Diagnostico;
use Telium\Http\Controllers\Email;
use Telium\Http\Controllers\Filas;
use Telium\Http\Controllers\Gravacoes;
use Telium\Http\Controllers\Horarios;
use Telium\Http\Controllers\Ura;
use Telium\Http\Controllers\Audios;
use Telium\Http\Controllers\Backup;
use Telium\Http\Controllers\Cli;
use Telium\Http\Controllers\Configuracao as CtrlConfig;
use Telium\Http\Controllers\Painel;
use Telium\Http\Controllers\Portal;
use Telium\Http\Controllers\Relatorios;
use Telium\Http\Controllers\Saude;
use Telium\Http\Controllers\Sistema;
use Telium\Http\Middleware\Autenticacao as MwAutenticacao;
use Telium\Http\Middleware\Permissao;
use Telium\Http\Middleware\Seguranca;
use Telium\Dominio\CodigosDiscagem;
use Telium\Dominio\Usuarios;
use Telium\Http\Recurso;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Resposta;

require __DIR__ . '/../vendor/autoload.php';

Ambiente::carregar(__DIR__ . '/../.env');

$app = AppFactory::create();

// Tudo mora sob /api, menos o provisionamento de telefones. A URL dele é
// digitada à mão no teclado de um aparelho, por quem está instalando, e
// cada segmento a mais é uma chance a mais de erro — ela já carrega um
// segredo de 32 caracteres.
$caminhoPedido = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// O /p/ do Linphone mora fora pelo mesmo motivo: a URL vai dentro de um
// QR Code, e QR menor é QR que a câmera lê de mais longe.
$app->setBasePath(str_starts_with($caminhoPedido, '/prov/') || str_starts_with($caminhoPedido, '/p/')
    ? '' : '/api');
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(new Seguranca());

$debug = Ambiente::bool('APP_DEBUG', false);
$erros = $app->addErrorMiddleware($debug, true, true);
$erros->setDefaultErrorHandler(
    function ($request, \Throwable $e) use ($app, $debug) {
        $status = match (true) {
            $e instanceof \Slim\Exception\HttpNotFoundException => 404,
            $e instanceof \Slim\Exception\HttpMethodNotAllowedException => 405,
            default => 500,
        };

        return Resposta::erro(
            $app->getResponseFactory()->createResponse(),
            $debug ? $e->getMessage() : 'Erro interno na API',
            $status,
            $debug ? ['arquivo' => $e->getFile(), 'linha' => $e->getLine()] : []
        );
    }
);

/* =========================================================
   Recursos REST — tabela, colunas graváveis e como filtrar
   ========================================================= */
/**
 * Telas que oferecem um seletor de destino.
 *
 * Para montar o seletor elas precisam LER a lista de ramais, filas,
 * URAs, anúncios e afins. Exigir o módulo dono de cada lista deixava 19
 * das 63 telas quebradas para qualquer perfil que não fosse o
 * administrador — a tela abria e toda chamada dela voltava 403.
 * Escolher não é administrar: isto libera só a listagem. Gravar, abrir
 * o cadastro e excluir seguem exigindo o dono.
 */
const ESCOLHEM_DESTINO = [
    'apps.ura', 'apps.filas', 'apps.anuncios', 'apps.condicoes', 'apps.grupohorario',
    'apps.estacionamento', 'apps.paging', 'apps.despertar', 'apps.conferencias',
    'apps.grupostoque', 'apps.disa', 'apps.sigame', 'apps.correiovoz', 'apps.caixaspostais',
    'conn.rotasentrada', 'conn.rotassaida', 'conn.provisionamento',
    'admin.listanegra', 'admin.allowlist', 'admin.destinos', 'cfg.musica', 'cfg.correiovoz',
];

$recursos = [
    'ramais' => [
        'modulo' => 'conn.ramais',
        // O telefone do navegador lista os ramais para discar e transferir.
        'referencia' => [...ESCOLHEM_DESTINO, 'fone.webrtc'],
        'recurso' => new Recurso(
            tabela: 'ramais',
            colunas: ['numero','nome','setor','email','tecnologia','senha_sip','contexto','transporte',
                      'codecs','max_contatos','srtp','webrtc','callgroup','pickupgroup','redes_permitidas',
                      'voicemail','vm_senha','vm_email','vm_apagar','vm_max_mensagens','vm_max_segundos',
                      'vm_dizer_hora','vm_dizer_origem','gravar','dnd',
                      'siga_me','siga_me_ativo','siga_me_modo','siga_me_toque','siga_me_confirmar',
                      'tempo_toque','pin_set_id','perm_local','perm_celular','perm_ddd','perm_ddi',
                      'no_diretorio','ativo',
                      // identificação
                      'pin','accountcode','alias_sip','cid_pseudo','did','did_descricao',
                      'cid_entrada','contexto_custom',
                      // sinalização
                      'dtmf_modo','trust_rpid','envia_rpid','envia_pai','send_connected',
                      'user_eq_phone','qualify_freq','timers_sessao','timers_expira',
                      'expira_max','expira_min','proxy_saida','contexto_mensagens',
                      // mídia
                      'codecs_negados','direct_media','media_address','rtp_simetrico',
                      'reescrever_contato','forcar_rport','usar_transporte_recebido',
                      'rtp_timeout','rtp_timeout_hold','max_audio','max_video',
                      'srtp_oportunista','remove_existing','refer_blind_progress',
                      // WebRTC e DTLS
                      'avpf','ice','rtcp_mux','dtls','dtls_verificar','dtls_setup','dtls_rekey',
                      // MWI
                      'mwi_tipo','mwi_agregado',
                      // discagem e atendimento
                      'opcoes_dial','toque_sigame','max_saidas','chamada_espera','tom_espera',
                      'rastreio_chamada','auto_resposta','interfonia','estado_em_fila',
                      // gravação por sentido
                      'grav_ext_entrada','grav_ext_saida','grav_int_entrada','grav_int_saida',
                      'grav_sob_demanda',
                      // ditado
                      'ditado','ditado_formato','ditado_email','ditado_remetente'],
            ordem: 'numero',
            busca: ['numero','nome','setor'],
            filtros: ['setor','ativo','gravar'],
            // O PIN do usuário autoriza chamada nas rotas que o pedem:
            // vale o mesmo cuidado da senha SIP.
            ocultas: ['senha_sip','vm_senha','pin'],
            // Para quem só escolhe um ramal (destinos, telefone, provisionamento).
            colunasReferencia: ['id', 'numero', 'nome', 'setor', 'ativo', 'webrtc'],
            referenciaCompleta: ['apps.sigame', 'apps.correiovoz'],
            afetaAsterisk: true,
            modulo: 'conn.ramais',
            regras: [
                'numero' => ['rotulo' => 'número do ramal', 'obrigatorio' => true,
                             'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número do ramal é só de dígitos, de 2 a 10.'],
                'nome' => ['rotulo' => 'nome', 'obrigatorio' => true, 'max' => 120],
                // Senha curta de ramal é a porta de entrada da fraude de
                // tarifação: um scanner acha em minutos e sai ligando.
                'senha_sip' => ['rotulo' => 'senha SIP', 'min' => 12,
                                'mensagem' => 'A senha SIP precisa de pelo menos 12 caracteres. '
                                            . 'É ela que protege o ramal contra fraude de tarifação.'],
                'email' => ['rotulo' => 'e-mail', 'email' => true],
                'vm_senha' => ['rotulo' => 'senha do correio de voz', 'padrao' => '/^[0-9]{0,10}$/',
                               'mensagem' => 'A senha do correio de voz é só de dígitos.'],
                // Trocar o contexto por um de saída faria o ramal pular a
                // checagem de permissão de discagem.
                'contexto' => ['rotulo' => 'contexto',
                               'em' => ['interno', 'telium-bloqueado'],
                               'mensagem' => 'O contexto do ramal é "interno", ou "telium-bloqueado" '
                                           . 'para deixá-lo sem saída. Outros contextos pulariam a '
                                           . 'checagem de permissão de discagem.'],
                // O contexto próprio é o que o administrador escreveu no
                // extensions_custom.conf. Os contextos do sistema ficam de fora:
                // apontar o ramal para um deles pulava a permissão de discagem.
                'contexto_custom' => ['rotulo' => 'contexto personalizado',
                                      'padrao' => '/^(?!telium|sub-|de-tronco$|interno$|default$)[A-Za-z0-9_-]{1,40}$/',
                                      'mensagem' => 'O contexto personalizado é um nome seu (letras, números, - e _), '
                                                  . 'que não comece com "telium" nem seja um contexto do sistema.'],
                'transporte' => ['rotulo' => 'transporte', 'em' => ['udp', 'tcp', 'tls', 'wss']],
                'pin' => ['rotulo' => 'PIN do usuário', 'padrao' => '/^[0-9]{0,10}$/',
                          'mensagem' => 'O PIN do usuário é só de dígitos.'],
                'dtmf_modo' => ['rotulo' => 'DTMF',
                                'em' => ['rfc4733', 'inband', 'info', 'auto', 'auto_info']],
                'interfonia' => ['rotulo' => 'interfonia', 'em' => ['permitir', 'negar']],
                'mwi_tipo' => ['rotulo' => 'MWI', 'em' => ['auto', 'solicitado', 'nao_solicitado']],
                'timers_sessao' => ['rotulo' => 'temporizador de sessão',
                                    'em' => ['nao', 'sim', 'obrigatorio']],
                'dtls_verificar' => ['rotulo' => 'verificação do DTLS',
                                     'em' => ['no', 'fingerprint', 'certificate', 'yes']],
                'dtls_setup' => ['rotulo' => 'papel no DTLS',
                                 'em' => ['active', 'passive', 'actpass']],
                'ditado_formato' => ['rotulo' => 'formato do ditado', 'em' => ['wav', 'gsm', 'ogg']],
                'grav_sob_demanda' => ['rotulo' => 'gravação sob demanda',
                                       'em' => ['desabilitado', 'ativar', 'sobrepor']],
                'gravar' => ['rotulo' => 'gravação', 'em' => ['nao', 'entrada', 'saida', 'ambas']],
                'siga_me' => ['rotulo' => 'siga-me', 'padrao' => '/^[0-9*#+]{2,20}$/',
                              'mensagem' => 'O destino do siga-me é um ramal ou um número.'],
            ],
            unicas: ['numero'],
            // Marcar WebRTC liga junto o que o navegador exige. Sem isto
            // o arquivo gerado ficava certo e o cadastro mostrava as
            // quatro opções desligadas — a tela mentindo sobre si mesma.
            normalizar: static function (array $dados, array $atual): array {
                $webrtc = (int) ($dados['webrtc'] ?? $atual['webrtc'] ?? 0);
                if ($webrtc !== 1) {
                    return $dados;
                }

                // max_contatos = 1 no WebRTC, e não é gosto: o endpoint
                // carrega DTLS, ICE e o transporte wss para TODO contato
                // registrado nele. Com duas vagas, um softphone comum se
                // registra ao lado do navegador e leva a chamada para um
                // caminho que não sabe falar — o Asterisk aceita, o ICE
                // nunca fecha e o áudio sai com zero byte. Uma vaga
                // fecha a porta para esse engano.
                foreach (['dtls' => 1, 'avpf' => 1, 'ice' => 1, 'rtcp_mux' => 1,
                          'transporte' => 'wss', 'srtp' => 1,
                          'max_contatos' => 1,
                          // O navegador apresenta certificado autoassinado:
                          // a confiança do DTLS vem da impressão digital no
                          // SDP. Exigir o certificado derruba o handshake e
                          // a chamada conecta muda.
                          'dtls_verificar' => 'fingerprint',
                          'dtls_setup' => 'actpass',
                          // Mídia direta obrigaria a outra ponta a falar
                          // DTLS-SRTP e fechar ICE com o navegador.
                          'direct_media' => 0] as $campo => $valor) {
                    $dados[$campo] = $valor;
                }

                // Codec que o navegador não fala é codec que não existe:
                // a chamada é aceita e não sobra nada em comum. Sobra o
                // que Chrome, Firefox, Safari e Edge oferecem de fato.
                $navegador = ['opus', 'g722', 'ulaw', 'alaw'];
                $escolhidos = array_values(array_intersect(
                    array_map('trim', explode(',', strtolower((string) ($dados['codecs'] ?? $atual['codecs'] ?? '')))),
                    $navegador
                ));
                $dados['codecs'] = implode(',', $escolhidos ?: ['opus', 'ulaw', 'alaw']);
                $dados['codecs_negados'] = null;

                return $dados;
            },
        ),
    ],
    'troncos' => [
        'modulo' => 'conn.troncos',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'troncos',
            colunas: ['nome','tipo','host','porta','transporte','usuario','senha','registrar','from_user',
                      'from_domain','contexto_entrada','did_remover','did_digitos',
                      'codecs','canais_max','cid_saida','ativo'],
            ordem: 'nome',
            busca: ['nome','host'],
            filtros: ['tipo','ativo'],
            ocultas: ['senha'],
            afetaAsterisk: true,
            modulo: 'conn.troncos',
            regras: [
                'nome' => ['rotulo' => 'nome do tronco', 'obrigatorio' => true, 'max' => 60],
                'host' => ['rotulo' => 'host da operadora', 'obrigatorio' => true],
                // Só PJSIP: o gerador não escreve outro tipo e o
                // chan_dahdi nem carrega nesta instalação. Aceitar
                // "dahdi" deixava o tronco cadastrado, o console dizia
                // "aplicado" e nada aparecia no Asterisk.
                'tipo' => ['rotulo' => 'tipo',
                           'em' => ['pjsip'],
                           'mensagem' => 'Esta central entronca por SIP. Placa analógica ou E1 '
                                       . 'exige chan_dahdi, que não faz parte desta instalação.'],
                'transporte' => ['rotulo' => 'transporte', 'em' => ['udp', 'tcp', 'tls']],
                // "From domain" com o número da conta gera
                // From: <sip:112658668@112658668>, um domínio que não
                // existe, e a operadora responde 404 sem dizer por quê.
                // Quem quer o número no From usa "From user".
                'from_domain' => [
                    'rotulo' => 'From domain',
                    'padrao' => '/^(?!\d+$)[A-Za-z0-9._-]+$/',
                    'mensagem' => 'From domain é o domínio ou IP da operadora — sip.operadora.com.br '
                                . 'ou 200.201.141.210. Para mandar o número da conta, use "From user". '
                                . 'Em branco, vale o host do tronco.',
                ],
                'from_user' => [
                    'rotulo' => 'From user',
                    'padrao' => '/^[^@\s]+$/',
                    'mensagem' => 'From user é só a parte antes do @ — sem arroba e sem espaço.',
                ],
            ],
        ),
    ],
    'filas' => [
        'modulo' => 'apps.filas',
        // As telas do call center escolhem fila (tabulação, agente, relatório).
        'referencia' => [...ESCOLHEM_DESTINO, 'cc.tabulacoes', 'cc.agentes', 'cc.relatorios', 'cc.retornos'],
        'recurso' => new Recurso(
            tabela: 'filas',
            colunas: ['numero','nome','descricao','callcenter','tabulacao_obrigatoria','retorno_tecla',
                      'retorno_anuncio_id','retorno_tentativas','ampliar_segundos','ampliar_ate',
                      'estrategia','timeout_agente','retry',
                      'wrapuptime','musica_espera','anuncio_entrada_id','anuncio_agente_id',
                      'anuncio_periodico_id','periodico_segundos','anuncio_posicao','anuncio_espera','anuncio_frequencia',
                      'sla_segundos','max_espera','peso','max_chamadas','entrar_vazia','sair_vazia',
                      'tocar_ocupado','pausa_automatica','atraso_atendimento','confirmar_atendimento',
                      'destino_estouro_tipo','destino_estouro_valor','destino_vazia_tipo',
                      'destino_vazia_valor','destino_cheia_tipo','destino_cheia_valor',
                      'pesquisa_id','gravar','ativo'],
            ordem: 'numero',
            busca: ['numero','nome'],
            filtros: ['ativo'],
            regras: [
                // O número vira o nome da extensão no dialplan.
                'numero' => ['rotulo' => 'número da fila', 'obrigatorio' => true, 'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número da fila é só de dígitos, de 2 a 10.'],
                'nome' => ['rotulo' => 'nome', 'obrigatorio' => true, 'max' => 80],
            ],
            afetaAsterisk: true,
            modulo: 'apps.filas',
        ),
    ],
    'estacionamentos' => [
        'modulo' => 'apps.estacionamento',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'estacionamentos',
            colunas: ['nome','descricao','padrao','numero_estacionar','vaga_inicio','vaga_fim',
                      'tempo_segundos','musica_espera','volta_para_origem','tempo_volta',
                      'destino_tipo','destino_valor','avisar_vaga','primeira_vaga_livre','ativo'],
            ordem: 'nome',
            busca: ['nome','descricao'],
            filtros: ['ativo'],
            regras: [
                'numero_estacionar' => ['rotulo' => 'número para estacionar', 'obrigatorio' => true,
                                        'padrao' => '/^[0-9*#]{1,10}$/', 'mensagem' => 'Só dígitos, * ou #.'],
                'vaga_inicio' => ['rotulo' => 'primeira vaga', 'padrao' => '/^[0-9]{1,10}$/', 'mensagem' => 'Só dígitos.'],
                'vaga_fim' => ['rotulo' => 'última vaga', 'padrao' => '/^[0-9]{1,10}$/', 'mensagem' => 'Só dígitos.'],
            ],
            afetaAsterisk: true,
            modulo: 'apps.estacionamento',
        ),
    ],
    'despertadores' => [
        'modulo' => 'apps.despertar',
        'recurso' => new Recurso(
            tabela: 'despertadores',
            colunas: ['nome','ramal','anuncio_id','repeticao','quando','hora','dias_semana',
                      'tentativas','intervalo','ativo'],
            ordem: 'nome',
            busca: ['nome','ramal'],
            filtros: ['ativo','ramal','repeticao'],
            modulo: 'apps.despertar',
        ),
    ],
    'grupos-horario' => [
        'modulo' => 'apps.grupohorario',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'grupos_horario',
            colunas: ['nome', 'descricao'],
            ordem: 'nome',
            busca: ['nome', 'descricao'],
            afetaAsterisk: true,
            modulo: 'apps.grupohorario',
        ),
    ],
    'condicoes-horarias' => [
        'modulo' => 'apps.condicoes',
        // Quem escolhe destino precisa ver a lista, mesmo sem este módulo.
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'condicoes_horarias',
            colunas: ['nome','descricao','grupo_horario_id','destino_dentro_tipo','destino_dentro_valor',
                      'destino_fora_tipo','destino_fora_valor','codigo_acao','codigo_pin','ativo'],
            ordem: 'nome',
            busca: ['nome', 'descricao'],
            filtros: ['ativo', 'grupo_horario_id'],
            afetaAsterisk: true,
            modulo: 'apps.condicoes',
            regras: [
                // O que o código *27<n> faz no telefone.
                'codigo_acao' => ['rotulo' => 'o que o código faz', 'em' => ['fechar', 'abrir', 'inverter']],
                // Visível de propósito: é um código de operação, e a tela
                // precisa mostrar que a condição está protegida.
                'codigo_pin' => ['rotulo' => 'PIN do código', 'padrao' => '/^[0-9]{4,10}$/',
                                 'mensagem' => 'O PIN do código é só de dígitos, de 4 a 10.'],
            ],
        ),
    ],
    'anuncios' => [
        'modulo' => 'apps.anuncios',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'anuncios',
            colunas: ['nome','descricao','audio_id','repetir_tecla','permitir_pular','retornar_ura',
                      'nao_responder','destino_tipo','destino_valor','ativo'],
            ordem: 'nome',
            busca: ['nome','descricao'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'apps.anuncios',
        ),
    ],
    // Caixa postal sem ramal: recado fora do horário, ouvido depois pelo
    // código de facilidade dela.
    'caixas-postais' => [
        'modulo' => 'apps.caixaspostais',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'caixas_postais',
            colunas: ['numero', 'nome', 'senha', 'codigo', 'pedir_senha', 'anuncio_id', 'email',
                      'vm_email', 'vm_apagar', 'vm_max_mensagens', 'vm_max_segundos', 'ativo'],
            ordem: 'numero',
            busca: ['numero', 'nome'],
            filtros: ['ativo'],
            ocultas: ['senha'],
            colunasReferencia: ['id', 'numero', 'nome', 'ativo'],
            afetaAsterisk: true,
            modulo: 'apps.caixaspostais',
            regras: [
                'numero' => ['rotulo' => 'número da caixa', 'obrigatorio' => true, 'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número da caixa tem de 2 a 10 dígitos.'],
                'nome'   => ['rotulo' => 'nome', 'obrigatorio' => true, 'padrao' => '/^.{2,80}$/u',
                             'mensagem' => 'O nome tem de 2 a 80 caracteres.'],
                'senha'  => ['padrao' => '/^[0-9]{4,12}$/', 'mensagem' => 'A senha tem de 4 a 12 dígitos.'],
                'codigo' => ['padrao' => CodigosDiscagem::FORMATO,
                             'mensagem' => 'Use * seguido de 1 a 6 dígitos (como *981).'],
                'email'  => ['email' => true],
            ],
            unicas: ['numero', 'codigo'],
            normalizar: static function (array $dados): array {
                foreach (['codigo', 'email'] as $c) {
                    if (array_key_exists($c, $dados) && trim((string) $dados[$c]) === '') {
                        $dados[$c] = null;
                    }
                }

                return $dados;
            },
            conferir: static function (string $acao, array $dados, array $atual): ?array {
                if ($acao === 'excluir') {
                    return null;
                }
                // A caixa do ramal e a avulsa moram no mesmo contexto do
                // voicemail.conf: o mesmo número seria a mesma caixa.
                $numero = (string) ($dados['numero'] ?? $atual['numero'] ?? '');
                if ($numero !== '' && \Telium\Suporte\Bd::valor('SELECT id FROM ramais WHERE numero = ?', [$numero])) {
                    return ['mensagem' => "{$numero} é número de ramal. Use um número que não seja de ramal.", 'campo' => 'numero'];
                }
                if ($acao === 'criar' && trim((string) ($dados['senha'] ?? '')) === '') {
                    return ['mensagem' => 'Dê uma senha à caixa: é ela que protege os recados.', 'campo' => 'senha'];
                }
                $codigo = trim((string) ($dados['codigo'] ?? $atual['codigo'] ?? ''));
                $ativo = (int) ($dados['ativo'] ?? $atual['ativo'] ?? 1) === 1;
                $mudou = $codigo !== (string) ($atual['codigo'] ?? '') || (int) ($atual['ativo'] ?? 0) !== 1;
                if ($codigo === '' || !$ativo || !$mudou) {
                    return null;
                }
                $colisao = CodigosDiscagem::colisao($codigo, 'caixa', isset($atual['id']) ? (int) $atual['id'] : null);

                return $colisao === null ? null : ['mensagem' => $colisao, 'campo' => 'codigo'];
            },
        ),
    ],
    'conferencias' => [
        'modulo' => 'apps.conferencias',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'conferencias',
            colunas: ['numero','nome','descricao','pin','pin_admin','anuncio_entrada_id','max_usuarios',
                      'gravar','anunciar_entrada_saida','anunciar_quantidade','musica_sozinho',
                      'silenciar_ao_entrar','esperar_admin','ativo'],
            ordem: 'numero',
            busca: ['numero','nome','descricao'],
            filtros: ['ativo'],
            // O PIN da sala é o que impede alguém de entrar na reunião:
            // não volta numa listagem. Em branco, ao editar, mantém.
            ocultas: ['pin','pin_admin'],
            regras: [
                'numero' => ['rotulo' => 'número da sala', 'obrigatorio' => true, 'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número da sala é só de dígitos, de 2 a 10.'],
                'pin' => ['rotulo' => 'PIN', 'padrao' => '/^[0-9]{0,10}$/', 'mensagem' => 'O PIN é só de dígitos.'],
                'pin_admin' => ['rotulo' => 'PIN do moderador', 'padrao' => '/^[0-9]{0,10}$/',
                                'mensagem' => 'O PIN do moderador é só de dígitos.'],
            ],
            afetaAsterisk: true,
            modulo: 'apps.conferencias',
        ),
    ],
    'pesquisas' => [
        'modulo' => 'apps.pesquisas',
        // A fila escolhe a pesquisa, e URA ou rota de entrada podem mandar
        // o cliente para ela como destino.
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'pesquisas',
            colunas: ['nome','numero','descricao','anuncio_saudacao_id','anuncio_pergunta_id',
                      'anuncio_obrigado_id','anuncio_invalida_id','nota_min','nota_max','sentido',
                      'tentativas','segundos','ativo'],
            ordem: 'nome',
            busca: ['nome','descricao','numero'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'apps.pesquisas',
            regras: [
                'nome' => ['rotulo' => 'nome', 'obrigatorio' => true, 'max' => 80],
                // O número vira extensão discável: o atendente transfere para ele.
                'numero' => ['rotulo' => 'número para transferir', 'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número para transferir é só de dígitos, de 2 a 10.'],
                // Nota de 0 a 10: até 9 é um dígito, e o 10 é o único de dois.
                'nota_min' => ['rotulo' => 'nota mínima', 'obrigatorio' => true, 'padrao' => '/^[0-9]$/',
                               'mensagem' => 'A nota mínima vai de 0 a 9.'],
                'nota_max' => ['rotulo' => 'nota máxima', 'obrigatorio' => true, 'padrao' => '/^([1-9]|10)$/',
                               'mensagem' => 'A nota máxima vai de 1 a 10.'],
                'sentido' => ['rotulo' => 'sentido da escala', 'em' => ['maior_melhor', 'menor_melhor']],
                'tentativas' => ['rotulo' => 'tentativas', 'padrao' => '/^[1-5]$/',
                                 'mensagem' => 'De 1 a 5 tentativas.'],
                'segundos' => ['rotulo' => 'tempo para digitar', 'padrao' => '/^([3-9]|[12][0-9]|30)$/',
                               'mensagem' => 'O tempo para digitar vai de 3 a 30 segundos.'],
            ],
            unicas: ['numero'],
            conferir: static fn (string $acao, array $dados, array $atual): ?array
                => \Telium\Dominio\Pesquisas::conferir($acao, $dados, $atual),
        ),
    ],
    'rotas-entrada' => [
        'modulo' => 'conn.rotasentrada',
        'recurso' => new Recurso(
            tabela: 'rotas_entrada',
            colunas: ['did','descricao','cid_origem','prefixo_cid','alertinfo','atender_antes',
                      'tocar_antes','pausa_seg','bloquear_anonimo','musica_espera',
                      'destino_tipo','destino_valor','gravar','ordem','ativo'],
            ordem: 'ordem, id',
            busca: ['did','descricao'],
            regras: [
                // Aceita como a pessoa escreve ("x.", "1133 25xx"): o
                // normalizar abaixo põe o "_" e as maiúsculas.
                'did' => ['rotulo' => 'número chamado', 'padrao' => '/^(?:\*|s|qualquer|_?[0-9XZNxzn.!\[\]+\s-]{1,40})$/',
                          'mensagem' => 'O número chamado é o número exato (só dígitos), um padrão como 1140XXXXXX, '
                                      . 'ou X. (ou *) para qualquer número.'],
                'cid_origem' => ['rotulo' => 'quem liga', 'padrao' => '/^_?[0-9XZNxzn.!\[\]+\s-]{1,40}$/',
                                 'mensagem' => 'Quem liga é um número exato ou um padrão como 11XXXXXXXXX.'],
            ],
            normalizar: static fn (array $dados): array => array_merge($dados, array_map(
                static fn ($v) => \Telium\Gerador\Padrao::normalizar((string) $v),
                array_intersect_key($dados, ['did' => 1, 'cid_origem' => 1])
            )),
            afetaAsterisk: true,
            modulo: 'conn.rotasentrada',
        ),
    ],
    'rotas-saida' => [
        'modulo' => 'conn.rotassaida',
        'recurso' => new Recurso(
            tabela: 'rotas_saida',
            // O padrão e os prefixos moram em rota_saida_padroes, um por
            // forma de discar (9XXXXXXXX e 09XXXXXXXX na mesma rota, cada
            // um com o seu prefixo a tirar). As colunas antigas da tabela
            // ficaram vazias na migração 44 e não são mais gravadas.
            colunas: ['nome','ordem','tronco_id','tronco_falha_id','pin_set_id','classe','ativo'],
            ordem: 'ordem, id',
            busca: ['nome'],
            afetaAsterisk: true,
            modulo: 'conn.rotassaida',
            lista: [
                'campo'    => 'padroes',
                'ler'      => \Telium\Dominio\RotasSaida::padroesPorRota(...),
                'preparar' => \Telium\Dominio\RotasSaida::prepararPadroes(...),
                'gravar'   => \Telium\Dominio\RotasSaida::gravarPadroes(...),
            ],
        ),
    ],
    'ura' => [
        'modulo' => 'apps.ura',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'ura',
            colunas: ['nome','anuncio_id','timeout_digito','tentativas','discagem_direta',
                      'destino_timeout_tipo','destino_timeout_valor','destino_invalido_tipo',
                      'destino_invalido_valor','ativo'],
            ordem: 'nome',
            busca: ['nome'],
            afetaAsterisk: true,
            modulo: 'apps.ura',
        ),
    ],
    'ura-opcoes' => [
        'modulo' => 'apps.ura',
        'recurso' => new Recurso(
            tabela: 'ura_opcoes',
            colunas: ['ura_id','tecla','rotulo','destino_tipo','destino_valor','ordem'],
            ordem: 'ura_id, ordem',
            filtros: ['ura_id'],
            regras: [
                // A mesma regra do PUT /ura/{id}/opcoes: esta rota genérica a pulava.
                'tecla' => ['rotulo' => 'tecla', 'obrigatorio' => true,
                            'padrao' => '/^(?:[0-9*#]{1,4}|_[0-9*#XZNxzn._\[\]-]{1,12})$/',
                            'mensagem' => 'A tecla é um dígito, * ou #, ou um padrão como _1X.'],
            ],
            afetaAsterisk: true,
            modulo: 'apps.ura',
        ),
    ],
    'grupos-toque' => [
        'modulo' => 'apps.grupostoque',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'grupos_toque',
            colunas: ['numero','nome','estrategia','ramais','tempo_toque','destino_falha_tipo',
                      'destino_falha_valor','ativo'],
            ordem: 'numero',
            busca: ['numero','nome'],
            regras: [
                'numero' => ['rotulo' => 'número do grupo', 'obrigatorio' => true, 'padrao' => '/^[0-9]{2,10}$/',
                             'mensagem' => 'O número do grupo é só de dígitos, de 2 a 10.'],
                // Só ramais: um membro com "@tronco" discava para fora sem
                // passar pela permissão de ninguém.
                'ramais' => ['rotulo' => 'ramais', 'obrigatorio' => true,
                             'padrao' => '/^[0-9]{2,10}(-[0-9]{2,10})*$/',
                             'mensagem' => 'Os membros do grupo são números de ramal separados por "-".'],
            ],
            afetaAsterisk: true,
            modulo: 'apps.grupostoque',
        ),
    ],
    'usuarios' => [
        'modulo' => 'admin.usuarios',
        'recurso' => new Recurso(
            tabela: 'usuarios',
            colunas: ['usuario','nome','email','perfil_id','ramal','setor','status'],
            ordem: 'nome',
            busca: ['usuario','nome','email'],
            filtros: ['perfil_id','status'],
            ocultas: ['senha_hash','totp_secret'],
            modulo: 'admin.usuarios',
            regras: [
                'nome'    => ['rotulo' => 'Nome completo', 'obrigatorio' => true, 'max' => 120],
                'usuario' => ['rotulo' => 'Usuário de login', 'obrigatorio' => true],
                'email'   => ['rotulo' => 'E-mail', 'email' => true, 'max' => 160],
                'setor'   => ['rotulo' => 'Setor', 'max' => 80],
            ],
            unicas: ['usuario'],
            // O CRUD genérico confere campo por campo e não enxerga o
            // resto: é aqui que se impede excluir o último administrador,
            // desativar a própria conta ou vincular um ramal que já é de
            // outra pessoa. Nada disso dá erro de banco — dá um console
            // sem dono, ou duas pessoas no mesmo correio de voz.
            conferir: [Usuarios::class, 'conferir'],
        ),
    ],
    'destinos-personalizados' => [
        'modulo' => 'admin.destinos',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'destinos_personalizados',
            colunas: ['nome','descricao','contexto','extensao','prioridade','ativo'],
            ordem: 'nome',
            busca: ['nome','contexto','descricao'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'admin.destinos',
        ),
    ],
    'dispositivos' => [
        'modulo' => 'conn.provisionamento',
        'recurso' => new Recurso(
            tabela: 'dispositivos',
            // "ip", "firmware", "estado", "visto_em" e "vezes" são
            // escritos pelo próprio aparelho quando ele busca a
            // configuração: não entram no formulário, senão um valor
            // digitado à mão passaria por medição.
            colunas: ['mac','modelo','fabricante','ramal_id','observacao'],
            ordem: 'mac',
            busca: ['mac','modelo','ip'],
            filtros: ['estado'],
            unicas: ['mac'],
            regras: [
                // O MAC é a chave que o aparelho usa para se
                // identificar: errado, ele nunca acha a configuração e
                // ninguém entende por quê.
                'mac' => ['rotulo' => 'MAC address',
                          'padrao' => '/^[0-9A-Fa-f]{2}([:.-]?[0-9A-Fa-f]{2}){5}$/',
                          'mensagem' => 'O MAC tem doze dígitos hexadecimais, com ou sem separador. '
                                      . 'Ex.: 00:11:22:33:44:55'],
                'fabricante' => ['rotulo' => 'fabricante',
                                 'em' => ['grandstream', 'yealink', 'fanvil']],
            ],
            modulo: 'conn.provisionamento',
        ),
    ],
    'grupos-paging' => [
        'modulo' => 'apps.paging',
        // Quem escolhe destino precisa ver a lista, mesmo sem este módulo.
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'grupos_paging',
            colunas: ['numero', 'nome', 'ramais', 'duplex', 'anuncio_id', 'forcar',
                      'duracao_max', 'ativo'],
            ordem: 'numero',
            busca: ['numero', 'nome'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'apps.paging',
            regras: [
                'numero' => ['rotulo' => 'número do grupo', 'obrigatorio' => true,
                             'padrao' => '/^[0-9*#]{2,10}$/',
                             'mensagem' => 'O número do grupo é o que se disca para falar: '
                                         . 'só dígitos, * ou #.'],
                'nome' => ['rotulo' => 'nome', 'obrigatorio' => true, 'max' => 80],
                'ramais' => ['rotulo' => 'aparelhos', 'obrigatorio' => true],
            ],
            unicas: ['numero'],
        ),
    ],
    'disa' => [
        'modulo' => 'apps.disa',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'disa',
            colunas: ['nome', 'senha', 'contexto', 'cid_saida', 'tempo_digito',
                      'tentativas', 'responder', 'ativo'],
            ordem: 'nome',
            busca: ['nome'],
            // A senha da DISA vale tanto quanto a senha de um ramal:
            // com ela se disca pela conta do cliente. Não volta para a
            // tela, e editar sem mandá-la mantém a que está.
            ocultas: ['senha'],
            afetaAsterisk: true,
            modulo: 'apps.disa',
            regras: [
                'nome' => ['rotulo' => 'nome', 'obrigatorio' => true, 'max' => 80],
                // DISA sem senha é a porta aberta clássica da fraude de
                // tarifação: quem achar o número liga o mundo pela sua conta.
                'senha' => ['rotulo' => 'senha', 'obrigatorio' => true, 'min' => 6,
                            'padrao' => '/^[0-9]{6,20}$/',
                            'mensagem' => 'A senha da DISA é de 6 a 20 dígitos. Ela é o que '
                                        . 'separa a sua central de quem discar o número por acaso.'],
                'contexto' => ['rotulo' => 'contexto',
                               'em' => ['interno', 'telium-ramais', 'telium-bloqueado'],
                               'mensagem' => 'O contexto decide até onde quem entrou pode discar. '
                                           . '"telium-ramais" limita a ramais internos; "interno" '
                                           . 'libera também as rotas de saída permitidas.'],
            ],
            unicas: ['nome'],
        ),
    ],
    'voicemail-geral' => [
        'modulo' => 'cfg.correiovoz',
        'recurso' => new Recurso(
            tabela: 'voicemail_geral',
            colunas: ['max_mensagens', 'max_segundos', 'min_segundos', 'max_tentativas',
                      'formato', 'anexar', 'dizer_hora', 'dizer_origem',
                      'apagar_apos_email', 'assunto', 'corpo'],
            afetaAsterisk: true,
            modulo: 'cfg.correiovoz',
            modelos: ['assunto', 'corpo'],
            regras: [
                'max_segundos' => ['rotulo' => 'duração máxima', 'padrao' => '/^[0-9]{1,4}$/',
                                   'mensagem' => 'A duração máxima é em segundos, só dígitos.'],
                'formato' => ['rotulo' => 'formato',
                              'padrao' => '/^[a-z0-9|]{3,40}$/',
                              'mensagem' => 'Os formatos são separados por barra vertical, '
                                          . 'por exemplo wav49|gsm|wav.'],
            ],
        ),
    ],
    'fax-geral' => [
        'modulo' => 'cfg.fax',
        'recurso' => new Recurso(
            tabela: 'fax_geral',
            colunas: ['ativo', 'ecm', 'cabecalho', 'email_destino', 'minimo_bits', 'maximo_bits'],
            afetaAsterisk: true,
            modulo: 'cfg.fax',
            regras: [
                'email_destino' => ['rotulo' => 'e-mail de destino', 'email' => true],
            ],
        ),
    ],
    'pin-sets' => [
        'modulo' => 'cfg.pinsets',
        'referencia' => ESCOLHEM_DESTINO,
        'recurso' => new Recurso(
            tabela: 'pin_sets',
            colunas: ['nome', 'pins', 'no_cdr'],
            ordem: 'nome',
            busca: ['nome'],
            // Os PINs autorizam chamada: não voltam numa listagem, que é
            // o que acaba em captura de tela e em log de navegador.
            ocultas: ['pins'],
            afetaAsterisk: true,
            modulo: 'cfg.pinsets',
            regras: [
                'nome' => ['rotulo' => 'nome do conjunto', 'obrigatorio' => true, 'max' => 60],
                'pins' => ['rotulo' => 'PINs', 'obrigatorio' => true,
                           'padrao' => '/^[0-9\s,;]+$/',
                           'mensagem' => 'Os PINs são só dígitos, um por linha. '
                                       . 'Letra ou símbolo faz o Authenticate recusar o PIN certo.'],
            ],
            unicas: ['nome'],
        ),
    ],
    'tarifas' => [
        'modulo' => 'telium.tarifacao',
        // A mesma tabela aparece em duas telas: Tarifação e a Tabela de
        // Tarifas. Não é destino de chamada — a referência aqui é só a
        // outra tela que a lê.
        'referencia' => ['cfg.tarifas'],
        // A Tabela de Tarifas é a tela que cadastra: sem isto ela listava e
        // dava 403 ao salvar.
        'escrita' => ['cfg.tarifas'],
        'recurso' => new Recurso(
            tabela: 'tarifas',
            colunas: ['nome','classe','padrao','custo_minuto','taxa_fixa','incremento_seg',
                      'primeiro_incremento_seg','ordem','ativo'],
            ordem: 'ordem, id',
            busca: ['nome','padrao'],
            modulo: 'telium.tarifacao',
        ),
    ],
    'codigos-recurso' => [
        'modulo' => 'admin.codigos',
        'recurso' => new Recurso(
            tabela: 'codigos_recurso',
            // 'chave' é gravável só para quem cadastra um código próprio;
            // nos do catálogo ela é a identidade que o gerador consulta.
            colunas: ['chave', 'nome', 'codigo', 'tipo', 'argumento', 'descricao',
                      'categoria', 'ordem', 'ativo'],
            ordem: 'categoria, ordem, codigo',
            busca: ['nome', 'codigo', 'descricao'],
            filtros: ['categoria', 'ativo'],
            afetaAsterisk: true,
            modulo: 'admin.codigos',
            // Um código igual a outro, ou começo de outro — inclusive o de
            // uma pausa do call center —, faz um dos dois parar de
            // funcionar. Só se confere o que muda: trocar o código ou
            // religar um desligado.
            conferir: static function (string $acao, array $dados, array $atual): ?array {
                if ($acao === 'excluir' || ($dados['tipo'] ?? $atual['tipo'] ?? 'dialplan') !== 'dialplan') {
                    return null;
                }
                $codigo = trim((string) ($dados['codigo'] ?? $atual['codigo'] ?? ''));
                $ativo = (int) ($dados['ativo'] ?? $atual['ativo'] ?? 1) === 1;
                $mudou = $codigo !== (string) ($atual['codigo'] ?? '') || (int) ($atual['ativo'] ?? 0) !== 1;
                if (!$ativo || !$mudou) {
                    return null;
                }
                $colisao = CodigosDiscagem::colisao($codigo, 'recurso', isset($atual['id']) ? (int) $atual['id'] : null);

                return $colisao === null ? null : ['mensagem' => $colisao, 'campo' => 'codigo'];
            },
        ),
    ],
    'lista-negra' => [
        'modulo' => 'admin.listanegra',
        'recurso' => new Recurso(
            tabela: 'lista_negra',
            // anuncio_id faltava: o formulário oferecia "tocar um anúncio
            // e desligar", a escolha era descartada aqui em silêncio, e o
            // gerador — que lê ln.anuncio_id — sempre achava vazio. O
            // bloqueio funcionava; o anúncio, nunca.
            colunas: ['numero', 'descricao', 'tratamento', 'audio_id', 'anuncio_id', 'ativo'],
            ordem: 'numero',
            busca: ['numero', 'descricao'],
            filtros: ['tratamento', 'ativo'],
            afetaAsterisk: true,
            modulo: 'admin.listanegra',
        ),
    ],
    'lista-permitida' => [
        'modulo' => 'admin.allowlist',
        'recurso' => new Recurso(
            tabela: 'lista_permitida',
            colunas: ['numero', 'descricao', 'ativo'],
            ordem: 'numero',
            busca: ['numero', 'descricao'],
            afetaAsterisk: true,
            modulo: 'admin.allowlist',
        ),
    ],
    'backup-rotinas' => [
        'modulo' => 'admin.backup',
        'recurso' => new Recurso(
            tabela: 'backup_rotinas',
            colunas: ['nome', 'periodicidade', 'hora', 'dia_semana', 'dia_mes', 'inclui_banco',
                      'inclui_config', 'inclui_audios', 'inclui_gravacoes', 'retencao', 'ativo'],
            ordem: 'id',
            busca: ['nome'],
            modulo: 'admin.backup',
        ),
    ],
    // ---- call center: motivos de pausa e tabulações ----
    'cc-pausas' => [
        'modulo' => 'cc.pausas',
        // O painel do agente e o monitor leem a lista para montar o menu
        // de pausa; ler não é administrar.
        'referencia' => ['cc.agente', 'cc.supervisor'],
        'recurso' => new Recurso(
            tabela: 'cc_pausas_motivos',
            colunas: ['nome', 'codigo', 'limite_minutos', 'produtiva', 'ordem', 'ativo'],
            ordem: 'ordem, nome',
            busca: ['nome'],
            // O código de cada pausa vira uma extensão em telium-recursos.
            afetaAsterisk: true,
            modulo: 'cc.pausas',
            regras: [
                'nome'   => ['padrao' => '/^.{2,40}$/u', 'mensagem' => 'O nome tem de 2 a 40 caracteres.'],
                'codigo' => ['padrao' => CodigosDiscagem::FORMATO,
                             'mensagem' => 'Use * seguido de 1 a 6 dígitos (como *14). Vazio: a pausa só se faz pelo painel.'],
            ],
            unicas: ['nome', 'codigo'],
            // Sem código é NULL, e não "": a chave única aceita várias
            // pausas sem código, mas só uma com "".
            normalizar: static function (array $dados): array {
                if (array_key_exists('codigo', $dados) && trim((string) $dados['codigo']) === '') {
                    $dados['codigo'] = null;
                }

                return $dados;
            },
            // "Pós-atendimento" e "Não atendeu" são postos pela central, e
            // o nome é o que o código procura no queue_log. Renomear ou
            // apagar quebraria a tabulação obrigatória sem erro nenhum.
            conferir: static function (string $acao, array $dados, array $atual): ?array {
                if ((int) ($atual['sistema'] ?? 0) !== 1) {
                    // O código da pausa divide espaço com os códigos de recurso.
                    $codigo = trim((string) ($dados['codigo'] ?? $atual['codigo'] ?? ''));
                    $ativo = (int) ($dados['ativo'] ?? $atual['ativo'] ?? 1) === 1;
                    $mudou = $codigo !== (string) ($atual['codigo'] ?? '') || (int) ($atual['ativo'] ?? 0) !== 1;
                    if ($acao === 'excluir' || $codigo === '' || !$ativo || !$mudou) {
                        return null;
                    }
                    $colisao = CodigosDiscagem::colisao($codigo, 'pausa', isset($atual['id']) ? (int) $atual['id'] : null);

                    return $colisao === null ? null : ['mensagem' => $colisao, 'campo' => 'codigo'];
                }
                if ($acao === 'excluir') {
                    return ['mensagem' => 'Este motivo é da própria central e não pode ser excluído. Desative-o, se não quiser usá-lo.'];
                }
                if (isset($dados['nome']) && $dados['nome'] !== $atual['nome']) {
                    return ['mensagem' => 'O nome deste motivo é usado pela central e não pode mudar.', 'campo' => 'nome'];
                }
                if (isset($dados['codigo']) && trim((string) $dados['codigo']) !== '') {
                    return ['mensagem' => 'Este motivo é posto pela central, não discado: não tem código.', 'campo' => 'codigo'];
                }

                return null;
            },
        ),
    ],
    'cc-tabulacoes' => [
        'modulo' => 'cc.tabulacoes',
        'referencia' => ['cc.agente', 'cc.supervisor'],
        'recurso' => new Recurso(
            tabela: 'cc_tabulacoes',
            colunas: ['nome', 'grupo', 'fila_id', 'ordem', 'ativo'],
            ordem: 'grupo, ordem, nome',
            busca: ['nome', 'grupo'],
            filtros: ['fila_id', 'ativo'],
            modulo: 'cc.tabulacoes',
            regras: [
                'nome' => ['padrao' => '/^.{2,80}$/u', 'mensagem' => 'O nome tem de 2 a 80 caracteres.'],
            ],
        ),
    ],
    'integracoes' => [
        'modulo' => 'telium.integracoes',
        'recurso' => new Recurso(
            tabela: 'integracoes',
            colunas: ['nome','tipo','url','segredo','eventos','ativo'],
            ordem: 'nome',
            busca: ['nome'],
            ocultas: ['segredo'],
            modulo: 'telium.integracoes',
        ),
    ],
];

/* ---------------------------------------------------------
   Rotas públicas
   --------------------------------------------------------- */
$app->get('/health', Saude::class);

// A única porta sem sessão além do login e do health, e é assim porque
// tem de ser: um telefone de mesa não faz login. O que protege é o
// conjunto — segredo no caminho, MAC precisar estar cadastrado e rede de
// origem conferida. Cada pedido, atendido ou não, fica registrado.
$app->get('/prov/{segredo}/{arquivo}', [Provisionar::class, 'entregar']);
// O Linphone lendo o QR Code. Protegido pelo token: aleatório, dez
// minutos, uma vez só.
$app->get('/p/{token}', [Provisionar::class, 'linphone']);
$app->post('/auth/login', [CtrlAuth::class, 'login']);

/* ---------------------------------------------------------
   Rotas autenticadas
   --------------------------------------------------------- */
$app->group('', function (RouteCollectorProxy $g) use ($recursos) {
    $g->post('/auth/logout', [CtrlAuth::class, 'logout']);
    $g->get('/me', [CtrlAuth::class, 'eu']);
    // Sem módulo nem ação: é a própria conta de quem está logado.
    $g->post('/me/senha', [CtrlAuth::class, 'trocarMinhaSenha']);
    $g->post('/me/2fa/iniciar', [CtrlAuth::class, 'iniciar2fa']);
    $g->post('/me/2fa/confirmar', [CtrlAuth::class, 'confirmar2fa']);
    $g->delete('/me/2fa', [CtrlAuth::class, 'desligar2fa']);

    // ---- portal do usuário: tudo preso ao ramal da sessão ----
    // Click-to-call: o ramal de origem é sempre o da sessão, então a
    // porta é a mesma do "Meu Ramal". As telas que oferecem o botão —
    // agenda, relatório de chamadas — entram por referência.
    $g->post('/discar', [Portal::class, 'discar'])
      ->add(new Permissao('pcu.meuramal', null,
            ['pcu.contatos', 'pcu.chamadas', 'admin.contatos', 'rel.cdr', 'conn.ramais', 'fone.webrtc']));

    // ---- telefone do navegador (WebRTC): só quem tem o módulo ----
    $g->get('/me/telefone', [Portal::class, 'telefone'])->add(new Permissao('fone.webrtc'));
    $g->put('/me/telefone/dnd', [Portal::class, 'dnd'])->add(new Permissao('fone.webrtc'));

    $g->get('/me/ramal', [Portal::class, 'ramal'])
      ->add(new Permissao('pcu.meuramal', null, ['pcu.sigame', 'pcu.perfil']));
    $g->put('/me/ramal', [Portal::class, 'salvarRamal'])
      ->add(new Permissao('pcu.meuramal', null, ['pcu.sigame']));
    $g->get('/me/chamadas', [Portal::class, 'chamadas'])->add(new Permissao('pcu.chamadas'));
    // O softphone do navegador deixa aqui o resumo do áudio de cada
    // chamada. Exige só "ter um ramal": é o próprio dono relatando a
    // própria ligação, e o ramal vem da sessão.
    // O resumo que o telefone do navegador manda ao fim de cada chamada:
    // vale para quem tem o módulo do telefone, com ou sem o Meu Ramal.
    $g->post('/me/webrtc/chamada', [Portal::class, 'registrarChamadaWebrtc'])
      ->add(new Permissao('pcu.meuramal', null, ['fone.webrtc']));
    $g->get('/me/correiovoz', [Portal::class, 'correioVoz'])->add(new Permissao('pcu.correiovoz'));
    $g->get('/me/correiovoz/audio', [Portal::class, 'audioCorreio'])
      ->add(new Permissao('pcu.correiovoz'));
    $g->delete('/me/correiovoz', [Portal::class, 'apagarCorreio'])
      ->add(new Permissao('pcu.correiovoz'));

    // ---- painel e operação ----
    $g->get('/painel/visaogeral', [Painel::class, 'visaogeral'])
      ->add(new Permissao('dash.visaogeral'));
    $g->get('/tempo-real', [Sistema::class, 'tempoReal'])
      ->add(new Permissao('dash.temporeal'));
    $g->get('/sistema/estatisticas', [Sistema::class, 'estatisticas'])
      ->add(new Permissao('dash.sistema'));
    $g->get('/sistema/asterisk', [Sistema::class, 'asteriskInfo'])
      ->add(new Permissao('dash.asterisk'));

    // ---- relatórios ----
    $g->get('/cdr', [Relatorios::class, 'cdr'])->add(new Permissao('rel.cdr'));
    $g->get('/relatorios/filas', [Relatorios::class, 'filas'])->add(new Permissao('rel.filas'));
    $g->get('/relatorios/agentes', [Relatorios::class, 'agentes'])->add(new Permissao('rel.agentes'));
    $g->get('/relatorios/ramais', [Relatorios::class, 'ramais'])->add(new Permissao('rel.ramais'));
    $g->get('/relatorios/troncos', [Relatorios::class, 'troncos'])->add(new Permissao('rel.troncos'));
    $g->get('/relatorios/eventos', [Relatorios::class, 'eventos'])->add(new Permissao('rel.cel'));
    $g->get('/relatorios/tarifacao', [Relatorios::class, 'tarifacao'])->add(new Permissao('telium.tarifacao'));
    $g->post('/relatorios/tarifacao', [Relatorios::class, 'tarifar'])
      ->add(new Permissao('telium.tarifacao', 'editar'));
    // ---- arquivo de gravações ----
    // Não há rota de exclusão, de propósito: gravação é prova de
    // atendimento. Quem limpa o disco é a retenção do backup.
    $g->get('/gravacoes', [Gravacoes::class, 'listar'])->add(new Permissao('apps.gravacao'));
    $g->get('/gravacoes/arquivo', [Gravacoes::class, 'arquivo'])->add(new Permissao('apps.gravacao'));
    $g->post('/gravacoes/pacote', [Gravacoes::class, 'pacote'])
      ->add(new Permissao('apps.gravacao', 'exportar'));
    // Auditoria é só do administrador: a trilha diz quem mexeu em quê, com
    // IP e horário, e sob a chave 'rel.logs' ela era alcançada pelo
    // curinga 'rel.*' que supervisor e auditor têm.
    $g->get('/auditoria', [Relatorios::class, 'auditoria'])->add(new Permissao('admin.auditoria'));

    // ---- cadastros especiais ----
    $g->get('/perfis', [Cadastros::class, 'perfis'])
      ->add(new Permissao('admin.permissoes', null, ['admin.usuarios']));
    $g->post('/perfis', [Cadastros::class, 'criarPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->put('/perfis/{id}', [Cadastros::class, 'atualizarPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->delete('/perfis/{id}', [Cadastros::class, 'removerPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->put('/perfis/{id}/permissoes', [Cadastros::class, 'salvarPermissoes'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    // ---- diagnóstico: só leitura, do estado real do Asterisk ----
    $g->get('/diagnostico/rede', [Diagnostico::class, 'rede'])->add(new Permissao('conn.rede'));
    // Mudar o IP público e as redes locais muda o NAT de todas as chamadas:
    // é edição, não consulta.
    $g->put('/diagnostico/rede', [Diagnostico::class, 'salvarRede'])->add(new Permissao('conn.rede', 'editar'));
    $g->get('/diagnostico/rede/descobrir', [Diagnostico::class, 'descobrirIp'])
      ->add(new Permissao('conn.rede'));
    $g->get('/diagnostico/webrtc', [Diagnostico::class, 'webrtc'])->add(new Permissao('conn.webrtc'));
    $g->post('/diagnostico/webrtc', [Diagnostico::class, 'ligarWebrtc'])
      ->add(new Permissao('conn.webrtc', 'editar'));
    $g->get('/diagnostico/seguranca', [Diagnostico::class, 'seguranca'])
      ->add(new Permissao('conn.firewall'));
    $g->get('/diagnostico/banidos', [Diagnostico::class, 'banidos'])
      ->add(new Permissao('conn.firewall'));
    $g->post('/diagnostico/desbanir', [Diagnostico::class, 'desbanir'])
      ->add(new Permissao('conn.firewall', 'editar'));
    $g->get('/firewall/confiaveis', [Diagnostico::class, 'confiaveis'])
      ->add(new Permissao('conn.firewall'));
    $g->post('/firewall/confiaveis', [Diagnostico::class, 'adicionarConfiavel'])
      ->add(new Permissao('conn.firewall', 'editar'));
    $g->delete('/firewall/confiaveis/{id}', [Diagnostico::class, 'removerConfiavel'])
      ->add(new Permissao('conn.firewall', 'editar'));
    $g->get('/diagnostico/sip', [Diagnostico::class, 'sip'])->add(new Permissao('cfg.sip'));
    $g->get('/diagnostico/dids', [Diagnostico::class, 'dids'])->add(new Permissao('conn.did'));
    $g->get('/diagnostico/troncos', [Diagnostico::class, 'troncos'])
      ->add(new Permissao('conn.troncos'));

    // ---- envio de e-mail ----
    $g->get('/email', [Email::class, 'obter'])->add(new Permissao('cfg.notificacoes'));
    $g->put('/email', [Email::class, 'salvar'])
      ->add(new Permissao('cfg.notificacoes', 'editar'));
    $g->post('/email/testar', [Email::class, 'testar'])
      ->add(new Permissao('cfg.notificacoes', 'editar'));

    $g->get('/empresa', [Cadastros::class, 'empresa']);
    $g->put('/empresa', [Cadastros::class, 'salvarEmpresa'])
      ->add(new Permissao('cfg.empresa', 'editar'));
    $g->post('/usuarios', [Cadastros::class, 'criarUsuario'])
      ->add(new Permissao('admin.usuarios', 'criar'));
    $g->post('/usuarios/{id}/senha', [Cadastros::class, 'trocarSenha'])
      ->add(new Permissao('admin.usuarios', 'editar'));
    // Celular perdido e conta travada por senha errada são os dois
    // chamados que o console não sabia atender — a saída era o banco.
    $g->post('/usuarios/{id}/2fa/desligar', [Cadastros::class, 'desligar2faDe'])
      ->add(new Permissao('admin.usuarios', 'editar'));
    $g->post('/usuarios/{id}/destravar', [Cadastros::class, 'destravar'])
      ->add(new Permissao('admin.usuarios', 'editar'));
    // ---- ramais em planilha: antes do CRUD, que leria "csv" como id ----
    $ramaisCsv = new RamaisCsv($recursos['ramais']['recurso']);
    $g->get('/ramais/csv/modelo', [$ramaisCsv, 'modelo'])->add(new Permissao('conn.ramais'));
    $g->get('/ramais/csv', [$ramaisCsv, 'exportar'])->add(new Permissao('conn.ramais', 'exportar'));
    $g->post('/ramais/csv', [$ramaisCsv, 'importar'])->add(new Permissao('conn.ramais', 'criar'));
    $g->get('/ramais/{id}/credenciais', [Cadastros::class, 'credenciaisRamal'])
      ->add(new Permissao('conn.ramais', 'editar'));

    // ---- backup e restauração ----
    $g->get('/backup', [Backup::class, 'estado'])->add(new Permissao('admin.backup'));
    $g->post('/backup/executar', [Backup::class, 'executar'])
      ->add(new Permissao('admin.backup', 'criar'));
    $g->get('/backup/destino', [Backup::class, 'destino'])->add(new Permissao('admin.backup'));
    $g->put('/backup/destino', [Backup::class, 'salvarDestino'])
      ->add(new Permissao('admin.backup', 'editar'));
    $g->post('/backup/destino/testar', [Backup::class, 'testarDestino'])
      ->add(new Permissao('admin.backup', 'editar'));
    $g->post('/backup/{id}/enviar', [Backup::class, 'enviar'])
      ->add(new Permissao('admin.backup', 'exportar'));
    $g->get('/backup/{id}/baixar', [Backup::class, 'baixar'])
      ->add(new Permissao('admin.backup', 'exportar'));
    $g->post('/backup/{id}/restaurar', [Backup::class, 'restaurar'])
      ->add(new Permissao('admin.backup', 'reiniciar'));
    $g->delete('/backup/{id}', [Backup::class, 'remover'])
      ->add(new Permissao('admin.backup', 'excluir'));

    // ---- áudios do sistema ----
    $g->get('/audios', [Audios::class, 'listar'])
      ->add(new Permissao('admin.gravacoes', null, ESCOLHEM_DESTINO));
    $g->post('/audios', [Audios::class, 'enviar'])
      ->add(new Permissao('admin.gravacoes', 'criar', ['cfg.musica']));
    $g->get('/audios/{id}/ouvir', [Audios::class, 'ouvir'])->add(new Permissao('admin.gravacoes', null, ['cfg.musica']));
    $g->put('/audios/{id}', [Audios::class, 'atualizar'])
      ->add(new Permissao('admin.gravacoes', 'editar', ['cfg.musica']));
    $g->delete('/audios/{id}', [Audios::class, 'remover'])
      ->add(new Permissao('admin.gravacoes', 'excluir', ['cfg.musica']));

    // ---- call center ----
    $g->get('/cc/estado', [CtrlCallCenter::class, 'estado'])
      ->add(new Permissao('cc.agente', null, ['cc.supervisor']));
    $g->get('/cc/eu', [CtrlCallCenter::class, 'eu'])->add(new Permissao('cc.agente'));
    $g->post('/cc/eu/entrar', [CtrlCallCenter::class, 'entrar'])->add(new Permissao('cc.agente'));
    $g->post('/cc/eu/sair', [CtrlCallCenter::class, 'sair'])->add(new Permissao('cc.agente'));
    $g->post('/cc/eu/pausa', [CtrlCallCenter::class, 'pausar'])->add(new Permissao('cc.agente'));
    $g->post('/cc/eu/volta', [CtrlCallCenter::class, 'voltar'])->add(new Permissao('cc.agente'));
    $g->post('/cc/atendimentos/{id}/tabular', [CtrlCallCenter::class, 'tabular'])->add(new Permissao('cc.agente'));
    $g->post('/cc/agentes/{id}/comando', [CtrlCallCenter::class, 'comando'])
      ->add(new Permissao('cc.supervisor', 'editar'));
    $g->get('/cc/agentes', [CtrlCallCenter::class, 'listar'])
      ->add(new Permissao('cc.agentes', null, ['cc.supervisor']));
    $g->post('/cc/agentes', [CtrlCallCenter::class, 'salvar'])->add(new Permissao('cc.agentes', 'criar'));
    $g->put('/cc/agentes/{id}', [CtrlCallCenter::class, 'salvar'])->add(new Permissao('cc.agentes', 'editar'));
    $g->delete('/cc/agentes/{id}', [CtrlCallCenter::class, 'remover'])->add(new Permissao('cc.agentes', 'excluir'));
    $g->get('/cc/retornos', [CtrlCallCenter::class, 'retornos'])
      ->add(new Permissao('cc.retornos', null, ['cc.supervisor']));
    $g->post('/cc/retornos/{id}', [CtrlCallCenter::class, 'retorno'])->add(new Permissao('cc.retornos', 'editar'));
    $g->get('/cc/config', [CtrlCallCenter::class, 'config'])->add(new Permissao('cc.config'));
    $g->put('/cc/config', [CtrlCallCenter::class, 'salvarConfig'])->add(new Permissao('cc.config', 'editar'));
    $g->get('/cc/relatorio', [CtrlCallCenter::class, 'relatorio'])->add(new Permissao('cc.relatorios'));

    // ---- provisionamento de telefones ----
    $g->get('/provisionamento', [Provisionar::class, 'estado'])
      ->add(new Permissao('conn.provisionamento'));
    $g->put('/provisionamento', [Provisionar::class, 'salvar'])
      ->add(new Permissao('conn.provisionamento', 'editar'));
    $g->get('/provisionamento/{id}/previa', [Provisionar::class, 'previa'])
      ->add(new Permissao('conn.provisionamento', 'editar'));
    $g->post('/provisionamento/linphone', [Provisionar::class, 'gerarLinphone'])
      ->add(new Permissao('conn.provisionamento', 'editar'));

    // ---- certificados TLS ----
    $g->get('/certificados', [Certificados::class, 'listar'])->add(new Permissao('admin.certificados'));
    $g->post('/certificados', [Certificados::class, 'enviar'])
      ->add(new Permissao('admin.certificados', 'criar'));
    $g->post('/certificados/autoassinado', [Certificados::class, 'autoassinado'])
      ->add(new Permissao('admin.certificados', 'criar'));
    $g->post('/certificados/csr', [Certificados::class, 'csr'])
      ->add(new Permissao('admin.certificados', 'criar'));
    $g->post('/certificados/aplicar', [Certificados::class, 'aplicar'])
      ->add(new Permissao('admin.certificados', 'reiniciar'));
    $g->get('/certificados/{id}/pem', [Certificados::class, 'pem'])
      ->add(new Permissao('admin.certificados'));
    $g->post('/certificados/{id}/assinar', [Certificados::class, 'assinar'])
      ->add(new Permissao('admin.certificados', 'editar'));
    $g->put('/certificados/{id}', [Certificados::class, 'atualizar'])
      ->add(new Permissao('admin.certificados', 'editar'));
    $g->delete('/certificados/{id}', [Certificados::class, 'remover'])
      ->add(new Permissao('admin.certificados', 'excluir'));

    // ---- agenda de contatos ----
    // Não entra no CRUD genérico: quem só tem a agenda pessoal enxerga os
    // contatos corporativos mas mexe apenas nos próprios, e isso é decidido
    // por linha, dentro do controller.
    // O telefone do navegador lê a agenda (pessoal e global) para discar.
    $g->get('/contatos', [Contatos::class, 'listar'])
      ->add(new Permissao('pcu.contatos', null, ['admin.contatos', 'fone.webrtc']));
    // A agenda da empresa é editada por quem tem o Gerenciador de Contatos,
    // mesmo sem a agenda pessoal; o controlador separa o que é de quem.
    $g->get('/contatos/{id}', [Contatos::class, 'obter'])->add(new Permissao('pcu.contatos', null, ['admin.contatos']));
    $g->post('/contatos', [Contatos::class, 'criar'])
      ->add(new Permissao('pcu.contatos', 'criar', ['admin.contatos']));
    $g->put('/contatos/{id}', [Contatos::class, 'atualizar'])
      ->add(new Permissao('pcu.contatos', 'editar', ['admin.contatos']));
    $g->delete('/contatos/{id}', [Contatos::class, 'remover'])
      ->add(new Permissao('pcu.contatos', 'excluir', ['admin.contatos']));
    $g->post('/contatos/{id}/foto', [Contatos::class, 'foto'])
      ->add(new Permissao('pcu.contatos', 'editar', ['admin.contatos']));
    $g->delete('/contatos/{id}/foto', [Contatos::class, 'removerFoto'])
      ->add(new Permissao('pcu.contatos', 'editar', ['admin.contatos']));

    // ---- destinos personalizados ----
    $g->get('/destinos/contextos', [Destinos::class, 'contextos'])
      ->add(new Permissao('admin.destinos'));

    // ---- filas: agentes e situação ----
    $g->get('/filas/{id}/agentes', [Filas::class, 'agentes'])->add(new Permissao('apps.filas'));
    $g->put('/filas/{id}/cc-agentes', [Filas::class, 'salvarAgentesCallCenter'])
      ->add(new Permissao('apps.filas', 'editar'));
    $g->put('/filas/{id}/agentes', [Filas::class, 'salvarAgentes'])
      ->add(new Permissao('apps.filas', 'editar'));
    $g->get('/filas/{id}/situacao', [Filas::class, 'situacao'])->add(new Permissao('apps.filas'));
    // ---- pesquisa de satisfação: resultados (antes do CRUD de /pesquisas/{id}) ----
    $g->get('/pesquisas/resultados', [CtrlPesquisas::class, 'resultados'])->add(new Permissao('apps.pesquisas'));
    $g->get('/pesquisas/respostas', [CtrlPesquisas::class, 'respostas'])->add(new Permissao('apps.pesquisas'));
    $g->get('/pesquisas/respostas/csv', [CtrlPesquisas::class, 'csv'])
      ->add(new Permissao('apps.pesquisas', 'exportar'));

    // ---- URA: entradas do menu ----
    $g->get('/ura/{id}/opcoes', [Ura::class, 'opcoes'])->add(new Permissao('apps.ura'));
    $g->put('/ura/{id}/opcoes', [Ura::class, 'salvarOpcoes'])
      ->add(new Permissao('apps.ura', 'editar'));

    // ---- conferências ----
    $g->get('/conferencias/{id}/situacao', [Conferencias::class, 'situacao'])
      ->add(new Permissao('apps.conferencias'));

    // ---- grupos de horário: as faixas ----
    $g->get('/grupos-horario/{id}/faixas', [Horarios::class, 'faixas'])
      ->add(new Permissao('apps.grupohorario'));
    $g->put('/grupos-horario/{id}/faixas', [Horarios::class, 'salvarFaixas'])
      ->add(new Permissao('apps.grupohorario', 'editar'));
    $g->get('/condicoes-horarias/{id}/agora', [Horarios::class, 'agora'])
      ->add(new Permissao('apps.condicoes'));
    $g->post('/condicoes-horarias/{id}/forcar', [Horarios::class, 'forcar'])
      ->add(new Permissao('apps.condicoes', 'editar'));

    // ---- console do Asterisk ----
    $g->get('/cli/sugestoes', [Cli::class, 'sugestoes'])->add(new Permissao('admin.cli'));
    $g->post('/cli', [Cli::class, 'executar'])->add(new Permissao('admin.cli'));

    // ---- configuração do Asterisk ----
    $g->get('/config/estado', [CtrlConfig::class, 'estado']);
    $g->get('/config/arquivos', [CtrlConfig::class, 'arquivos'])
      ->add(new Permissao('cfg.avancadas'));
    $g->post('/config/gerar', [CtrlConfig::class, 'gerar'])
      ->add(new Permissao('cfg.avancadas', 'editar'));
    $g->post('/config/aplicar', [CtrlConfig::class, 'aplicar'])
      ->add(new Permissao('cfg.avancadas', 'reiniciar'));

    // ---- CRUD genérico ----
    foreach ($recursos as $caminho => $def) {
        $r = $def['recurso'];
        $modulo = $def['modulo'];

        $g->get("/{$caminho}", [$r, 'listar'])
          ->add(new Permissao($modulo, null, $def['referencia'] ?? []));
        // 'escrita': outra tela que também cadastra neste recurso.
        $escrita = $def['escrita'] ?? [];
        $g->get("/{$caminho}/{id}", [$r, 'obter'])->add(new Permissao($modulo, null, $escrita));

        // usuarios tem criação própria (senha), declarada acima
        if ($caminho !== 'usuarios') {
            $g->post("/{$caminho}", [$r, 'criar'])->add(new Permissao($modulo, 'criar', $escrita));
        }
        $g->put("/{$caminho}/{id}", [$r, 'atualizar'])->add(new Permissao($modulo, 'editar', $escrita));
        $g->delete("/{$caminho}/{id}", [$r, 'remover'])->add(new Permissao($modulo, 'excluir', $escrita));
    }
})->add(new MwAutenticacao());

// A bateria de testes monta o mesmo aplicativo para percorrer as rotas
// sem subir servidor nenhum; nesse caso ela é que decide o que fazer
// com ele. Fora daí, nada muda.
if (defined('TELIUM_SEM_RUN')) {
    return $app;
}

$app->run();
