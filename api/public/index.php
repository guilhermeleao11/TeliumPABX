<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Telium\Http\Controllers\Autenticacao as CtrlAuth;
use Telium\Http\Controllers\Cadastros;
use Telium\Http\Controllers\Configuracao as CtrlConfig;
use Telium\Http\Controllers\Painel;
use Telium\Http\Controllers\Relatorios;
use Telium\Http\Controllers\Saude;
use Telium\Http\Controllers\Sistema;
use Telium\Http\Middleware\Autenticacao as MwAutenticacao;
use Telium\Http\Middleware\Permissao;
use Telium\Http\Middleware\Seguranca;
use Telium\Http\Recurso;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Resposta;

require __DIR__ . '/../vendor/autoload.php';

Ambiente::carregar(__DIR__ . '/../.env');

$app = AppFactory::create();
$app->setBasePath('/api');
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
$recursos = [
    'ramais' => [
        'modulo' => 'conn.ramais',
        'recurso' => new Recurso(
            tabela: 'ramais',
            colunas: ['numero','nome','setor','email','tecnologia','senha_sip','contexto','transporte',
                      'codecs','max_contatos','srtp','webrtc','callgroup','pickupgroup','redes_permitidas',
                      'voicemail','vm_senha','vm_email','vm_apagar','gravar','dnd','siga_me','tempo_toque',
                      'pin_set_id','perm_local','perm_celular','perm_ddd','perm_ddi','no_diretorio','ativo'],
            ordem: 'numero',
            busca: ['numero','nome','setor'],
            filtros: ['setor','ativo','gravar'],
            ocultas: ['senha_sip','vm_senha'],
            afetaAsterisk: true,
            modulo: 'conn.ramais',
        ),
    ],
    'troncos' => [
        'modulo' => 'conn.troncos',
        'recurso' => new Recurso(
            tabela: 'troncos',
            colunas: ['nome','tipo','host','porta','transporte','usuario','senha','registrar','from_user',
                      'from_domain','contexto_entrada','codecs','canais_max','cid_saida','ativo'],
            ordem: 'nome',
            busca: ['nome','host'],
            filtros: ['tipo','ativo'],
            ocultas: ['senha'],
            afetaAsterisk: true,
            modulo: 'conn.troncos',
        ),
    ],
    'filas' => [
        'modulo' => 'apps.filas',
        'recurso' => new Recurso(
            tabela: 'filas',
            colunas: ['numero','nome','estrategia','timeout_agente','retry','wrapuptime','musica_espera',
                      'anuncio_posicao','sla_segundos','max_espera','destino_estouro_tipo',
                      'destino_estouro_valor','gravar','ativo'],
            ordem: 'numero',
            busca: ['numero','nome'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'apps.filas',
        ),
    ],
    'rotas-entrada' => [
        'modulo' => 'conn.rotasentrada',
        'recurso' => new Recurso(
            tabela: 'rotas_entrada',
            colunas: ['did','descricao','cid_origem','destino_tipo','destino_valor','gravar','ordem','ativo'],
            ordem: 'ordem, id',
            busca: ['did','descricao'],
            afetaAsterisk: true,
            modulo: 'conn.rotasentrada',
        ),
    ],
    'rotas-saida' => [
        'modulo' => 'conn.rotassaida',
        'recurso' => new Recurso(
            tabela: 'rotas_saida',
            colunas: ['nome','ordem','padrao','prefixo_remover','prefixo_adicionar','tronco_id',
                      'tronco_falha_id','pin_set_id','classe','ativo'],
            ordem: 'ordem, id',
            busca: ['nome','padrao'],
            afetaAsterisk: true,
            modulo: 'conn.rotassaida',
        ),
    ],
    'ura' => [
        'modulo' => 'apps.ura',
        'recurso' => new Recurso(
            tabela: 'ura',
            colunas: ['nome','audio','timeout_digito','tentativas','discagem_direta','destino_timeout_tipo',
                      'destino_timeout_valor','destino_invalido_tipo','destino_invalido_valor','ativo'],
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
            afetaAsterisk: true,
            modulo: 'apps.ura',
        ),
    ],
    'grupos-toque' => [
        'modulo' => 'apps.grupostoque',
        'recurso' => new Recurso(
            tabela: 'grupos_toque',
            colunas: ['numero','nome','estrategia','ramais','tempo_toque','destino_falha_tipo',
                      'destino_falha_valor','ativo'],
            ordem: 'numero',
            busca: ['numero','nome'],
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
        ),
    ],
    'contatos' => [
        'modulo' => 'pcu.contatos',
        'recurso' => new Recurso(
            tabela: 'contatos',
            colunas: ['usuario_id','nome','numero','grupo','favorito'],
            ordem: 'nome',
            busca: ['nome','numero'],
            filtros: ['grupo'],
            modulo: 'pcu.contatos',
        ),
    ],
    'dispositivos' => [
        'modulo' => 'conn.provisionamento',
        'recurso' => new Recurso(
            tabela: 'dispositivos',
            colunas: ['mac','modelo','fabricante','ramal_id','firmware','ip','estado'],
            ordem: 'mac',
            busca: ['mac','modelo','ip'],
            filtros: ['estado'],
            modulo: 'conn.provisionamento',
        ),
    ],
    'tarifas' => [
        'modulo' => 'telium.tarifacao',
        'recurso' => new Recurso(
            tabela: 'tarifas',
            colunas: ['nome','padrao','custo_minuto','taxa_fixa','incremento_seg','ativo'],
            ordem: 'nome',
            busca: ['nome','padrao'],
            modulo: 'telium.tarifacao',
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
$app->post('/auth/login', [CtrlAuth::class, 'login']);

/* ---------------------------------------------------------
   Rotas autenticadas
   --------------------------------------------------------- */
$app->group('', function (RouteCollectorProxy $g) use ($recursos) {
    $g->post('/auth/logout', [CtrlAuth::class, 'logout']);
    $g->get('/me', [CtrlAuth::class, 'eu']);

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
    $g->get('/relatorios/tarifacao', [Relatorios::class, 'tarifacao'])->add(new Permissao('telium.tarifacao'));
    $g->get('/gravacoes', [Relatorios::class, 'gravacoes'])->add(new Permissao('rel.gravacoes'));
    $g->get('/auditoria', [Relatorios::class, 'auditoria'])->add(new Permissao('rel.logs'));

    // ---- cadastros especiais ----
    $g->get('/perfis', [Cadastros::class, 'perfis'])->add(new Permissao('admin.permissoes'));
    $g->put('/perfis/{id}/permissoes', [Cadastros::class, 'salvarPermissoes'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->get('/empresa', [Cadastros::class, 'empresa']);
    $g->put('/empresa', [Cadastros::class, 'salvarEmpresa'])
      ->add(new Permissao('cfg.empresa', 'editar'));
    $g->post('/usuarios', [Cadastros::class, 'criarUsuario'])
      ->add(new Permissao('admin.usuarios', 'criar'));
    $g->post('/usuarios/{id}/senha', [Cadastros::class, 'trocarSenha'])
      ->add(new Permissao('admin.usuarios', 'editar'));
    $g->get('/ramais/{id}/credenciais', [Cadastros::class, 'credenciaisRamal'])
      ->add(new Permissao('conn.ramais', 'editar'));

    // ---- configuração do Asterisk ----
    $g->get('/config/estado', [CtrlConfig::class, 'estado']);
    $g->post('/config/gerar', [CtrlConfig::class, 'gerar'])
      ->add(new Permissao('admin.modulos', 'editar'));
    $g->post('/config/aplicar', [CtrlConfig::class, 'aplicar'])
      ->add(new Permissao('admin.modulos', 'reiniciar'));

    // ---- CRUD genérico ----
    foreach ($recursos as $caminho => $def) {
        $r = $def['recurso'];
        $modulo = $def['modulo'];

        $g->get("/{$caminho}", [$r, 'listar'])->add(new Permissao($modulo));
        $g->get("/{$caminho}/{id}", [$r, 'obter'])->add(new Permissao($modulo));

        // usuarios tem criação própria (senha), declarada acima
        if ($caminho !== 'usuarios') {
            $g->post("/{$caminho}", [$r, 'criar'])->add(new Permissao($modulo, 'criar'));
        }
        $g->put("/{$caminho}/{id}", [$r, 'atualizar'])->add(new Permissao($modulo, 'editar'));
        $g->delete("/{$caminho}/{id}", [$r, 'remover'])->add(new Permissao($modulo, 'excluir'));
    }
})->add(new MwAutenticacao());

$app->run();
