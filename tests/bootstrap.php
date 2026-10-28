<?php

declare(strict_types=1);

/*
 * Test bootstrap: bring the TEST_DB_* database to the latest schema before the
 * suite runs. SQLite (the local default) starts from a fresh file every run;
 * PostgreSQL / MySQL (CI) are migrated in place.
 */

use Logbook\Kernel;
use Logbook\Support\Config\AppEnvironment;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();

// K148: without phpunit.xml.dist, APP_ENV isn't `testing` and the settings
// name the app's own database, which the lines below would delete and migrate.
if ($settings->environment !== AppEnvironment::Testing) {
    fwrite(STDERR, "Refusing to run: APP_ENV is not \"testing\". Run phpunit with phpunit.xml.dist.\n");
    exit(1);
}

if ($settings->database->driver === DatabaseDriver::Sqlite && is_file($settings->database->name)) {
    unlink($settings->database->name);
}

fwrite(STDOUT, sprintf("Test database: %s\n", $settings->database->describe()));

Migrator::run('migrate');
