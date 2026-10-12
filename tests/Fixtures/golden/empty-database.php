<?php

declare(strict_types=1);

/*
 * Empty every backed-up table of the TEST_DB_* database (children first, as
 * a restore does), so bin/test-all-dbs.sh and bin/upgrade-check.sh can roll
 * the test database back to nothing: the rollback refuses while users are
 * left, and the golden household's tests leave five.
 *
 *   APP_ENV=testing php tests/Fixtures/golden/empty-database.php
 */

use Logbook\Kernel;
use Logbook\Repository\BackupRepository;
use Logbook\Support\Config\AppEnvironment;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Only ever the TEST_DB_* database, whatever APP_ENV the shell has (the dev container's is development).
$settings = Kernel::settings(['APP_ENV' => 'testing']);
if ($settings->environment !== AppEnvironment::Testing) {
    fwrite(STDERR, "Refusing: not the test database.\n");
    exit(1);
}
$repository = Kernel::createContainer($settings)->get(BackupRepository::class);
assert($repository instanceof BackupRepository);
$repository->replaceAll(array_fill_keys(BackupRepository::TABLES, []));
