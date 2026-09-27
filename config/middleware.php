<?php

declare(strict_types=1);

use Logbook\Middleware\BasePathMiddleware;
use Logbook\Middleware\LocaleMiddleware;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\ErrorHandler;
use Logbook\Support\Http\HtmlErrorRenderer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;

/*
 * Global middleware. Slim runs the LAST added FIRST, so this list is written
 * inner → outer. Resulting order (outer → inner), per spec.md §5:
 *
 *   error handling → base path → [session: Phase 1] → locale
 *   → [CSRF: Phase 1] → routing → [auth guard, per route group: Phase 1]
 */
return static function (App $app): void {
    $container = $app->getContainer();
    assert($container instanceof ContainerInterface);

    $settings = $container->get(AppSettings::class);
    assert($settings instanceof AppSettings);
    $logger = $container->get(LoggerInterface::class);
    assert($logger instanceof LoggerInterface);

    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $app->add(LocaleMiddleware::class);
    $app->add(BasePathMiddleware::class);

    $errorHandler = new ErrorHandler($app->getCallableResolver(), $app->getResponseFactory(), $logger);
    $errorHandler->registerErrorRenderer('text/html', HtmlErrorRenderer::class);
    $errorHandler->setDefaultErrorRenderer('text/html', HtmlErrorRenderer::class);

    $errorMiddleware = new ErrorMiddleware(
        $app->getCallableResolver(),
        $app->getResponseFactory(),
        $settings->debug,
        true,
        true,
        $logger,
    );
    $errorMiddleware->setDefaultErrorHandler($errorHandler);
    $app->add($errorMiddleware);
};
