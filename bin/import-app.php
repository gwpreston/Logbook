<?php

declare(strict_types=1);

/*
 * Import a Fuelio CSV export or backup ZIP (spec.md §7.13 *Importing from
 * another app*, Phase 31). Backups carry the fill-up photos and are often
 * hundreds of megabytes, so they are imported here rather than uploaded.
 *
 *   php bin/import-app.php <file.csv|file.zip> [--vehicle <id> | --create]
 *       [--as <username>] [--schedules] [--dry-run]
 *
 * It uses the mapping the web page would propose: the vehicle that already
 * holds rows of this export (else the one with its registration, else a
 * new one), the units from the file, Fuelio's categories and fuel codes by
 * default. --dry-run prints the preview and writes nothing. Everything is
 * written in one transaction, or nothing is.
 *
 * Docker: docker compose exec -u www-data app php bin/import-app.php /data/backup.fuelio.zip
 *
 * Exit code: 0 imported (or previewed), 1 refused or failed, 3 usage.
 */

use Logbook\Kernel;
use Logbook\Service\Import\App\Fuelio\FuelioCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_values(array_filter(array_slice((array) ($_SERVER['argv'] ?? []), 1), 'is_string'));
$container = Kernel::createContainer(Kernel::settings());
$command = $container->get(FuelioCommand::class);
assert($command instanceof FuelioCommand);

exit($command->run($args, STDOUT, STDERR));
