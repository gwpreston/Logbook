<?php

declare(strict_types=1);

/*
 * Put the demo back to its sample data (spec.md §7.36), by hand: the same
 * service the `demo_reset` job runs.
 *
 *   php bin/demo-reset.php [--yes]
 *
 * It refuses unless this database was seeded as a demo (it carries the
 * marker) *and* DEMO_MODE is on: nothing else is ever wiped. Without --yes
 * it asks first. Run it as the web server user (Docker: docker compose exec
 * -u www-data app php bin/demo-reset.php --yes).
 *
 * Exit code: 0 reset, 1 refused or failed, 2 not confirmed.
 */

use Logbook\Kernel;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Demo\DemoResetRefused;
use Logbook\Service\Demo\DemoResetJob;
use Logbook\Service\Demo\DemoResetter;
use Logbook\Service\Jobs\JobLocks;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = Kernel::createApp(Kernel::settings())->getContainer();

$mode = $container->get(DemoMode::class);
assert($mode instanceof DemoMode);
$resetter = $container->get(DemoResetter::class);
assert($resetter instanceof DemoResetter);

// The command line, as strings (the $argv global is not guaranteed to be set).
$args = array_values(array_filter((array) ($_SERVER['argv'] ?? []), 'is_string'));

if (!$mode->isActive()) {
    fwrite(STDERR, "Refused: this database was not seeded as a demo, or DEMO_MODE is off. Nothing was changed.\n");
    exit(1);
}

if (!in_array('--yes', $args, true)) {
    fwrite(STDOUT, "This deletes everything in this database and puts the sample data back. Type 'yes' to go on: ");
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'yes') {
        fwrite(STDERR, "Not confirmed. Nothing was changed.\n");
        exit(2);
    }
}

// The job's own lock: a reset by hand never overlaps the scheduled one.
$locks = $container->get(JobLocks::class);
assert($locks instanceof JobLocks);
$lock = $locks->acquire(DemoResetJob::NAME);
if ($lock === null) {
    fwrite(STDERR, "Refused: a reset is already running.\n");
    exit(1);
}

try {
    $at = $resetter->reset();
} catch (DemoResetRefused $refused) {
    fwrite(STDERR, 'Refused: ' . $refused->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'The reset failed and the old data is kept: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'The demo was reset at ' . $at->format('Y-m-d H:i:s') . " UTC.\n");
exit(0);
