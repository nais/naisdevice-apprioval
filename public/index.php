<?php declare(strict_types=1);

namespace Nais\Device\Approval;

use DI\Container;
use Dotenv\Dotenv;
use Dotenv\Exception\ValidationException;
use Nais\Device\Approval\Controllers\IndexController;
use Nais\Device\Approval\Controllers\MembershipController;
use Nais\Device\Approval\Controllers\SamlController;
use NAVIT\AzureAd\ApiClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Get env var as string
 *
 * If the variable does not exist an empty string is returned. Leading and trailing whitespace is
 * automatically stripped from the returned value.
 */
function env(string $key): string
{
    $value = $_ENV[$key] ?? '';
    return trim((string) $value);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

try {
    $requiredEnvVars = [
        'ISSUER_ENTITY_ID',
        'LOGIN_URL',
        'ACCESS_GROUP',
        'AAD_CLIENT_ID',
        'AAD_CLIENT_SECRET',
        'SAML_CERT',
        'DOMAIN',
    ];

    $dotenv->required($requiredEnvVars)->notEmpty();
} catch (ValidationException $e) {
    http_response_code(503);
    echo sprintf('Missing one or more required environment variable(s): %s', join(', ', $requiredEnvVars));
    exit;
}

define('DEBUG', '1' === env('DEBUG'));

// Create and populate container
$container = new Container();
$container->set(Twig::class, fn (): Twig => Twig::create(__DIR__ . '/../templates'));
$container->set(Session::class, fn (): Session => (new Session())->start());
$container->set(ApiClient::class, fn () => new ApiClient(env('AAD_CLIENT_ID'), env('AAD_CLIENT_SECRET'), env('DOMAIN')));
$container->set(SamlResponseValidator::class, fn () => new SamlResponseValidator(env('SAML_CERT')));
$container->set(IndexController::class, function (ContainerInterface $c): IndexController {
    /** @var ApiClient */
    $apiClient = $c->get(ApiClient::class);

    /** @var Twig */
    $twig = $c->get(Twig::class);

    /** @var Session */
    $session = $c->get(Session::class);

    return  new IndexController($apiClient, $twig, $session, env('LOGIN_URL'), env('ISSUER_ENTITY_ID'), env('ACCESS_GROUP'));
});
$container->set(SamlController::class, function (ContainerInterface $c): SamlController {
    /** @var Session */
    $session = $c->get(Session::class);

    /** @var SamlResponseValidator */
    $validator = $c->get(SamlResponseValidator::class);

    return new SamlController($session, $validator, env('LOGOUT_URL'));
});
$container->set(MembershipController::class, function (ContainerInterface $c): MembershipController {
    /** @var Session */
    $session = $c->get(Session::class);

    /** @var ApiClient */
    $apiClient = $c->get(ApiClient::class);

    return new MembershipController($session, $apiClient, env('ACCESS_GROUP'));
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// Register middleware
$app->addBodyParsingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));
$app
    ->addErrorMiddleware(DEBUG, true, true)
    ->setDefaultErrorHandler(function (Request $_, Throwable $exception, bool $displayErrorDetails) use ($app) {
        /** @var ContainerInterface */
        $container = $app->getContainer();

        /** @var Twig */
        $twig = $container->get(Twig::class);

        return $twig->render($app->getResponseFactory()->createResponse(500), 'error.html', [
            'errorMessage' => $displayErrorDetails ? $exception->getMessage() : 'An error occurred',
        ]);
    });

// Routes
$app->get('/', IndexController::class . ':index');
$app->post('/toggleMembership', MembershipController::class . ':toggle');
$app->post('/saml/acs', SamlController::class . ':acs');
$app->get('/saml/logout', SamlController::class . ':logout');
$app->get('/isAlive', fn (Request $_, Response $response): Response => $response);
$app->get('/isReady', fn (Request $_, Response $response): Response => $response);

// Run the app
$app->run();
