<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Support\Config\DatabaseDriver;
use Logbook\Support\Config\Env;
use Logbook\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

/** K156 (Phase 54.1): a server test run can't be pointed at the app's own database. */
final class TestDatabaseGuardTest extends TestCase
{
    public function testSqliteIsAlwaysAllowed(): void
    {
        self::assertNull(TestDatabaseGuard::refusal(DatabaseDriver::Sqlite, new Env([])));
    }

    public function testAServerRunWithoutATestDatabaseNameIsRefused(): void
    {
        foreach ([DatabaseDriver::Pgsql, DatabaseDriver::Mysql] as $driver) {
            $refusal = (string) TestDatabaseGuard::refusal($driver, new Env([]));
            self::assertStringContainsString('TEST_DB_NAME is not set', $refusal);
            self::assertNotNull(TestDatabaseGuard::refusal($driver, new Env(['TEST_DB_NAME' => ''])));
        }
    }

    public function testTheAppsOwnDatabaseIsRefusedByName(): void
    {
        self::assertStringContainsString(
            'own database',
            (string) TestDatabaseGuard::refusal(DatabaseDriver::Pgsql, new Env(['TEST_DB_NAME' => 'logbook'])),
            'DB_NAME defaults to logbook',
        );
        self::assertNotNull(
            TestDatabaseGuard::refusal(DatabaseDriver::Mysql, new Env(['TEST_DB_NAME' => 'garage', 'DB_NAME' => 'garage'])),
        );
    }

    public function testASeparateDatabaseIsAllowed(): void
    {
        self::assertNull(TestDatabaseGuard::refusal(DatabaseDriver::Mysql, new Env(['TEST_DB_NAME' => 'logbook_test'])));
        self::assertNull(TestDatabaseGuard::refusal(
            DatabaseDriver::Pgsql,
            new Env(['TEST_DB_NAME' => 'logbook_test', 'DB_NAME' => 'garage']),
        ));
    }
}
