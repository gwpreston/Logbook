<?php

declare(strict_types=1);

/*
 * Scheduled-task runner. Run it every 15 minutes from cron (bare-PHP install)
 * or the container scheduler (Docker, from Phase 4):
 *
 *   *\/15 * * * *  www-data  php /path/to/logbook/bin/run-scheduled-tasks.php
 *
 * Phase 0 placeholder: boots the app (proving config + container wiring work
 * from cron's environment) and exits. Phase 4 adds reminder evaluation and
 * notification delivery here.
 */

use Logbook\Kernel;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = Kernel::createContainer(Kernel::settings());
$logger = $container->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

$logger->debug('Scheduled tasks: nothing to run yet.');

exit(0);
