<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;
use Telium\Http\Controllers\Autenticacao as CtrlAuth;
use Telium\Http\Controllers\Cadastros;
use Telium\Http\Controllers\Certificados;
use Telium\Http\Controllers\Conferencias;
use Telium\Http\Controllers\Contatos;
use Telium\Http\Controllers\Destinos;
use Telium\Http\Controllers\Filas;
use Telium\Http\Controllers\Gravacoes;
use Telium\Http\Controllers\Ura;
use Telium\Http\Controllers\Audios;
use Telium\Http\Controllers\Backup;
use Telium\Http\Controllers\Cli;
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
            colunas: ['numero','nome','descricao','callcenter','estrategia','timeout_agente','retry',
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
            afetaAsterisk: true,
            modulo: 'apps.filas',
        ),
    ],
    'anuncios' => [
        'modulo' => 'apps.anuncios',
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
    'conferencias' => [
        'modulo' => 'apps.conferencias',
        'recurso' => new Recurso(
            tabela: 'conferencias',
            colunas: ['numero','nome','descricao','pin','pin_admin','anuncio_entrada_id','max_usuarios',
                      'gravar','anunciar_entrada_saida','anunciar_quantidade','musica_sozinho',
                      'silenciar_ao_entrar','esperar_admin','ativo'],
            ordem: 'numero',
            busca: ['numero','nome','descricao'],
            filtros: ['ativo'],
            afetaAsterisk: true,
            modulo: 'apps.conferencias',
        ),
    ],
    'pesquisas' => [
        'modulo' => 'apps.filas',
        'recurso' => new Recurso(
            tabela: 'pesquisas',
            colunas: ['nome','descricao','anuncio_pergunta_id','anuncio_obrigado_id','nota_min',
                      'nota_max','tentativas','segundos','ativo'],
            ordem: 'nome',
            busca: ['nome','descricao'],
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
    'destinos-personalizados' => [
        'modulo' => 'admin.destinos',
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
        ),
    ],
    'lista-negra' => [
        'modulo' => 'admin.listanegra',
        'recurso' => new Recurso(
            tabela: 'lista_negra',
            colunas: ['numero', 'descricao', 'tratamento', 'audio_id', 'ativo'],
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
    // ---- arquivo de gravações ----
    // Não há rota de exclusão, de propósito: gravação é prova de
    // atendimento. Quem limpa o disco é a retenção do backup.
    $g->get('/gravacoes', [Gravacoes::class, 'listar'])->add(new Permissao('apps.gravacao'));
    $g->get('/gravacoes/arquivo', [Gravacoes::class, 'arquivo'])->add(new Permissao('apps.gravacao'));
    $g->post('/gravacoes/pacote', [Gravacoes::class, 'pacote'])
      ->add(new Permissao('apps.gravacao', 'exportar'));
    $g->get('/auditoria', [Relatorios::class, 'auditoria'])->add(new Permissao('rel.logs'));

    // ---- cadastros especiais ----
    $g->get('/perfis', [Cadastros::class, 'perfis'])->add(new Permissao('admin.permissoes'));
    $g->post('/perfis', [Cadastros::class, 'criarPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->put('/perfis/{id}', [Cadastros::class, 'atualizarPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
    $g->delete('/perfis/{id}', [Cadastros::class, 'removerPerfil'])
      ->add(new Permissao('admin.permissoes', 'permissoes'));
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

    // ---- backup e restauração ----
    $g->get('/backup', [Backup::class, 'estado'])->add(new Permissao('admin.backup'));
    $g->post('/backup/executar', [Backup::class, 'executar'])
      ->add(new Permissao('admin.backup', 'criar'));
    $g->post('/backup/{id}/restaurar', [Backup::class, 'restaurar'])
      ->add(new Permissao('admin.backup', 'reiniciar'));
    $g->delete('/backup/{id}', [Backup::class, 'remover'])
      ->add(new Permissao('admin.backup', 'excluir'));

    // ---- áudios do sistema ----
    $g->get('/audios', [Audios::class, 'listar'])->add(new Permissao('admin.gravacoes'));
    $g->post('/audios', [Audios::class, 'enviar'])
      ->add(new Permissao('admin.gravacoes', 'criar'));
    $g->put('/audios/{id}', [Audios::class, 'atualizar'])
      ->add(new Permissao('admin.gravacoes', 'editar'));
    $g->delete('/audios/{id}', [Audios::class, 'remover'])
      ->add(new Permissao('admin.gravacoes', 'excluir'));

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
    $g->get('/contatos', [Contatos::class, 'listar'])->add(new Permissao('pcu.contatos'));
    $g->get('/contatos/{id}', [Contatos::class, 'obter'])->add(new Permissao('pcu.contatos'));
    $g->post('/contatos', [Contatos::class, 'criar'])
      ->add(new Permissao('pcu.contatos', 'criar'));
    $g->put('/contatos/{id}', [Contatos::class, 'atualizar'])
      ->add(new Permissao('pcu.contatos', 'editar'));
    $g->delete('/contatos/{id}', [Contatos::class, 'remover'])
      ->add(new Permissao('pcu.contatos', 'excluir'));
    $g->post('/contatos/{id}/foto', [Contatos::class, 'foto'])
      ->add(new Permissao('pcu.contatos', 'editar'));
    $g->delete('/contatos/{id}/foto', [Contatos::class, 'removerFoto'])
      ->add(new Permissao('pcu.contatos', 'editar'));

    // ---- destinos personalizados ----
    $g->get('/destinos/contextos', [Destinos::class, 'contextos'])
      ->add(new Permissao('admin.destinos'));

    // ---- filas: agentes e situação ----
    $g->get('/filas/{id}/agentes', [Filas::class, 'agentes'])->add(new Permissao('apps.filas'));
    $g->put('/filas/{id}/agentes', [Filas::class, 'salvarAgentes'])
      ->add(new Permissao('apps.filas', 'editar'));
    $g->get('/filas/{id}/situacao', [Filas::class, 'situacao'])->add(new Permissao('apps.filas'));
    $g->get('/pesquisas/resultados', [Filas::class, 'resultados'])->add(new Permissao('apps.filas'));

    // ---- URA: entradas do menu ----
    $g->get('/ura/{id}/opcoes', [Ura::class, 'opcoes'])->add(new Permissao('apps.ura'));
    $g->put('/ura/{id}/opcoes', [Ura::class, 'salvarOpcoes'])
      ->add(new Permissao('apps.ura', 'editar'));

    // ---- conferências ----
    $g->get('/conferencias/{id}/situacao', [Conferencias::class, 'situacao'])
      ->add(new Permissao('apps.conferencias'));

    // ---- console do Asterisk ----
    $g->get('/cli/sugestoes', [Cli::class, 'sugestoes'])->add(new Permissao('admin.cli'));
    $g->post('/cli', [Cli::class, 'executar'])->add(new Permissao('admin.cli'));

    // ---- configuração do Asterisk ----
    $g->get('/config/estado', [CtrlConfig::class, 'estado']);
    $g->post('/config/gerar', [CtrlConfig::class, 'gerar'])
      ->add(new Permissao('cfg.avancadas', 'editar'));
    $g->post('/config/aplicar', [CtrlConfig::class, 'aplicar'])
      ->add(new Permissao('cfg.avancadas', 'reiniciar'));

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
