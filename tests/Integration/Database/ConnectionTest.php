<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Logbook\Kernel;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\AppTestCase;

final class ConnectionTest extends AppTestCase
{
    public function testTrivialRoundTrip(): void
    {
        $connection = $this->connection($this->createApp());

        // pdo_pgsql returns 1, pdo_mysql may return '1': equal either way.
        self::assertEquals(1, $connection->fetchOne($connection->getDatabasePlatform()->getDummySelectSQL()));
    }

    public function testPlatformMatchesConfiguredDriver(): void
    {
        $connection = $this->connection($this->createApp());
        $platform = $connection->getDatabasePlatform();

        $expected = match (Kernel::settings()->database->driver) {
            DatabaseDriver::Pgsql => PostgreSQLPlatform::class,
            DatabaseDriver::Mysql => AbstractMySQLPlatform::class,
            DatabaseDriver::Sqlite => SQLitePlatform::class,
        };

        self::assertInstanceOf($expected, $platform);
    }

    public function testDatabaseSessionClockIsUtc(): void
    {
        $connection = $this->connection($this->createApp());

        $dbNow = $connection->fetchOne('SELECT CURRENT_TIMESTAMP');
        self::assertIsString($dbNow);

        // Compare wall-clock strings: if the session were not UTC they would
        // differ by the server's offset (whole hours).
        $parsed = new DateTimeImmutable(substr($dbNow, 0, 19), new DateTimeZone('UTC'));
        $phpNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertLessThan(120, abs($phpNow->getTimestamp() - $parsed->getTimestamp()));
    }
}
