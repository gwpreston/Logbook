<?php

declare(strict_types=1);

/*
 * The start of a demo (spec.md §7.36): with DEMO_MODE set, seed an empty
 * database with the sample data and mark it as a demo. Run by the Docker
 * entrypoint after the migrations; run it by hand on a bare-PHP install if
 * you would rather not wait for the first request to do it.
 *
 *   php bin/demo-seed.php
 *
 * It changes nothing but an empty database. DEMO_MODE on a database that
 * holds real data (or without a usable DEMO_PASSWORD) is refused with a
 * line at error level, and nothing is deleted.
 *
 * Exit code: 0 done or nothing to do, 1 refused or failed.
 */

use Logbook\Kernel;
use Logbook\Service\Demo\DemoBootstrap;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoState;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();
if (!$settings->demo->enabled) {
    fwrite(STDOUT, "DEMO_MODE is off; nothing to do.\n");
    exit(0);
}

$container = Kernel::createApp($settings)->getContainer();
$bootstrap = $container->get(DemoBootstrap::class);
assert($bootstrap instanceof DemoBootstrap);
$mode = $container->get(DemoMode::class);
assert($mode instanceof DemoMode);

try {
    if (!$bootstrap->ensure()) {
        fwrite(STDERR, "Another process is seeding the demo.\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'The demo could not be seeded: ' . $e->getMessage() . "\n");
    exit(1);
}

$mode->forget();
$state = $mode->state();
fwrite(STDOUT, match ($state) {
    DemoState::Active => "The demo is ready.\n",
    DemoState::Refused => "DEMO_MODE was refused; see the log. Nothing was changed.\n",
    default => "Nothing to do.\n",
});
exit($state === DemoState::Refused ? 1 : 0);
