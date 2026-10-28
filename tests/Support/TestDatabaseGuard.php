<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Support\Config\DatabaseDriver;
use Logbook\Support\Config\Env;

/**
 * K156: the bootstrap migrates the test database and the suite empties its
 * tables, so a PostgreSQL or MySQL run must name that database in
 * `TEST_DB_NAME` and it must not be the app's own (`DB_NAME`). Without
 * `TEST_DB_NAME` it would default to `logbook`, the app's default.
 */
final class TestDatabaseGuard
{
    /**
     * Why the run must be refused; null when it is safe to go on.
     */
    public static function refusal(DatabaseDriver $driver, Env $env): ?string
    {
        if ($driver === DatabaseDriver::Sqlite) {
            return null;
        }

        $name = $env->string('TEST_DB_NAME');
        if ($name === '') {
            return 'TEST_DB_NAME is not set: a PostgreSQL or MySQL run must name its own test database '
                . '(for example logbook_test).';
        }
        if ($name === $env->string('DB_NAME', 'logbook')) {
            return sprintf('TEST_DB_NAME "%s" is the app\'s own database (DB_NAME): the suite would empty it.', $name);
        }

        return null;
    }
}
