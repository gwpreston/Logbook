<?php

declare(strict_types=1);

/*
 * Block until the configured database accepts connections (used by the Docker
 * entrypoint before running migrations).
 *
 *   php bin/wait-for-db.php [--timeout=60]
 */

use Logbook\Kernel;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Support\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['timeout::']);
$rawTimeout = is_array($options) && isset($options['timeout']) && is_string($options['timeout']) ? $options['timeout'] : '60';
$timeout = max(1, (int) $rawTimeout);

$config = Kernel::settings()->database;

if ($config->driver === DatabaseDriver::Sqlite) {
    $dir = dirname($config->name);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, sprintf("logbook: cannot create SQLite directory %s\n", $dir));
        exit(1);
    }
    exit(0);
}

$deadline = time() + $timeout;
$lastError = '';

do {
    $connection = ConnectionFactory::create($config);
    try {
        $connection->fetchOne($connection->getDatabasePlatform()->getDummySelectSQL());
        fwrite(STDOUT, sprintf("logbook: database %s is ready\n", $config->describe()));
        exit(0);
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    } finally {
        $connection->close();
    }
    sleep(2);
} while (time() < $deadline);

fwrite(STDERR, sprintf(
    "logbook: database %s not reachable after %ds: %s\n",
    $config->describe(),
    $timeout,
    $lastError,
));
exit(1);
