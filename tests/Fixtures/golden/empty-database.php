<?php

declare(strict_types=1);

/*
 * Empty every backed-up table of the TEST_DB_* database (children first, as
 * a restore does), so bin/upgrade-check.sh can roll a PostgreSQL or MySQL
 * test database back to nothing: the rollback refuses while users are left.
 *
 *   APP_ENV=testing php tests/Fixtures/golden/empty-database.php
 */

use Logbook\Kernel;
use Logbook\Repository\BackupRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$repository = Kernel::createContainer(Kernel::settings())->get(BackupRepository::class);
assert($repository instanceof BackupRepository);
$repository->replaceAll(array_fill_keys(BackupRepository::TABLES, []));
