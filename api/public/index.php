<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Telium\Http\Controllers\Autenticacao as CtrlAuth;
use Telium\Http\Controllers\Configuracao as CtrlConfig;
use Telium\Http\Controllers\Saude;
use Telium\Http\Middleware\Autenticacao as MwAutenticacao;
use Telium\Http\Middleware\Permissao;
use Telium\Http\Middleware\Seguranca;
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
        $status = $e instanceof \Slim\Exception\HttpNotFoundException ? 404
            : ($e instanceof \Slim\Exception\HttpMethodNotAllowedException ? 405 : 500);

        return Resposta::erro(
            $app->getResponseFactory()->createResponse(),
            $debug ? $e->getMessage() : 'Erro interno na API',
            $status,
            $debug ? ['arquivo' => $e->getFile(), 'linha' => $e->getLine()] : []
        );
    }
);

// ---------------------------------------------------------------
// Rotas públicas
// ---------------------------------------------------------------
$app->get('/health', Saude::class);
$app->post('/auth/login', [CtrlAuth::class, 'login']);

// ---------------------------------------------------------------
// Rotas autenticadas
// ---------------------------------------------------------------
$app->group('', function ($grupo) {
    $grupo->post('/auth/logout', [CtrlAuth::class, 'logout']);
    $grupo->get('/me', [CtrlAuth::class, 'eu']);

    $grupo->get('/config/estado', [CtrlConfig::class, 'estado'])
          ->add(new Permissao('admin.modulos'));
    $grupo->post('/config/gerar', [CtrlConfig::class, 'gerar'])
          ->add(new Permissao('admin.modulos', 'editar'));
    $grupo->post('/config/aplicar', [CtrlConfig::class, 'aplicar'])
          ->add(new Permissao('admin.modulos', 'reiniciar'));
})->add(new MwAutenticacao());

$app->run();
