<?php

declare(strict_types=1);

/*
 * Test bootstrap: bring the TEST_DB_* database to the latest schema before the
 * suite runs. SQLite (the local default) starts from a fresh file every run;
 * PostgreSQL / MySQL (CI) are migrated in place.
 */

use Logbook\Kernel;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Kernel::settings();

if ($settings->database->driver === DatabaseDriver::Sqlite && is_file($settings->database->name)) {
    unlink($settings->database->name);
}

fwrite(STDOUT, sprintf("Test database: %s\n", $settings->database->describe()));

Migrator::run('migrate');
