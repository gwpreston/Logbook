<?php

declare(strict_types=1);

/*
 * Import a Fuelio CSV export or backup ZIP, or a Tracktor JSON export
 * (spec.md §7.13 *Importing from another app*, Phases 31 and 51). Backups
 * carry the fill-up photos and are often hundreds of megabytes, so they are
 * imported here rather than uploaded; Tracktor's attachments and photos
 * come from its uploads folder, or a ZIP of it, with --files.
 *
 *   php bin/import-app.php <file.csv|file.zip> [--vehicle <id> | --create]
 *       [--as <username>] [--schedules] [--skip-invalid] [--dry-run]
 *   php bin/import-app.php <export.json> [--files <folder|zip>]
 *       [--vehicle <id> | --create] [--as <username>] [--schedules]
 *       [--skip-invalid] [--dry-run]
 *
 * It uses the mapping the web page would propose: the vehicle that already
 * holds rows of this export (else the one with its registration, else a
 * new one), the units from the file, and the app's categories, fuel codes
 * or document types by default. --dry-run prints the preview and writes
 * nothing. Rows with problems stop the import unless
 * --skip-invalid says to import the others. Everything is written in one
 * transaction, or nothing is.
 *
 * Docker: docker compose exec -u www-data app php bin/import-app.php /data/backup.fuelio.zip
 *
 * Exit code: 0 imported (or previewed), 1 refused or failed, 3 usage.
 */

use Logbook\Kernel;
use Logbook\Service\Import\App\ImportAppCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_values(array_filter(array_slice((array) ($_SERVER['argv'] ?? []), 1), 'is_string'));
$container = Kernel::createContainer(Kernel::settings());
$command = $container->get(ImportAppCommand::class);
assert($command instanceof ImportAppCommand);

exit($command->run($args, STDOUT, STDERR));
