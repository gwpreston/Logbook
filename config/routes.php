<?php

declare(strict_types=1);

use Logbook\Action\DeepLinkCheckAction;
use Logbook\Action\HealthAction;
use Logbook\Action\HomeAction;
use Slim\App;

/*
 * Route map only: path → invokable Action class. No logic here (CLAUDE.md §5).
 * Paths are relative to APP_BASE_PATH; templates build URLs with url_for().
 */
return static function (App $app): void {
    $app->get('/', HomeAction::class)->setName('home');
    $app->get('/health', HealthAction::class)->setName('health');
    $app->get('/diagnostics/deep/link', DeepLinkCheckAction::class)->setName('diagnostics.deep-link');
};
