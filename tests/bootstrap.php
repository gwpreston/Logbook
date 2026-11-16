<?php

declare(strict_types=1);

/*
 * Test bootstrap: bring the TEST_DB_* database to the latest schema before the
 * suite runs. SQLite (the local default) starts from a fresh file every run;
 * PostgreSQL / MySQL (CI) are migrated in place. A second run from the same
 * checkout waits for the first (var/testing.lock, K197).
 */

use Logbook\Kernel;
use Logbook\Support\Config\AppEnvironment;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\Migrator;
use Logbook\Tests\Support\TestDatabaseGuard;
use Logbook\Tests\Support\TestRunLock;
use Logbook\Tests\Support\TestSqliteFile;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();

// K148: without phpunit.xml.dist, APP_ENV isn't `testing` and the settings
// name the app's own database, which the lines below would delete and migrate.
if ($settings->environment !== AppEnvironment::Testing) {
    fwrite(STDERR, "Refusing to run: APP_ENV is not \"testing\". Run phpunit with phpunit.xml.dist.\n");
    exit(1);
}

// K156: a server database must be named by TEST_DB_NAME and not be the app's own.
$refusal = TestDatabaseGuard::refusal($settings->database->driver, $settings->env);
if ($refusal !== null) {
    fwrite(STDERR, "Refusing to run: {$refusal}\n");
    exit(1);
}

// K197 (#619): one run at a time per checkout. Taken after the refusals
// above (which only read settings, so a refused run never waits, and the
// K148 test's child run can't wait on its parent) and before anything
// deletes the SQLite file, migrates or touches var/cache; held until the
// run ends.
TestRunLock::acquire(dirname(__DIR__) . '/var/testing.lock');

// K206 (#626): a SQLite file another process holds open can't be deleted
// (on Windows); stop rather than test on the old one.
if ($settings->database->driver === DatabaseDriver::Sqlite) {
    $refusal = TestSqliteFile::delete($settings->database->name);
    if ($refusal !== null) {
        fwrite(STDERR, $refusal . "\n");
        exit(1);
    }
}

fwrite(STDOUT, sprintf("Test database: %s\n", $settings->database->describe()));

Migrator::run('migrate');
