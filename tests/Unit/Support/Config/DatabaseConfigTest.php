<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Config;

use InvalidArgumentException;
use Logbook\Support\Config\DatabaseConfig;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testDefaultsToSqliteInsideTheProject(): void
    {
        $config = DatabaseConfig::fromEnv(new Env([]), '/app');

        self::assertSame(DatabaseDriver::Sqlite, $config->driver);
        self::assertSame('/app/var/logbook.sqlite', $config->name);
        self::assertSame(
            ['adapter' => 'sqlite', 'name' => '/app/var/logbook.sqlite', 'suffix' => ''],
            $config->toPhinxEnvironment(),
        );
    }

    public function testAbsoluteSqlitePathIsKept(): void
    {
        $config = DatabaseConfig::fromEnv(new Env(['DB_DRIVER' => 'sqlite', 'DB_NAME' => '/data/logbook.sqlite']), '/app');

        self::assertSame('/data/logbook.sqlite', $config->name);
    }

    public function testPostgresWithDefaultPort(): void
    {
        $config = DatabaseConfig::fromEnv(new Env([
            'DB_DRIVER' => 'postgres',
            'DB_HOST' => 'db',
            'DB_NAME' => 'garage',
            'DB_USER' => 'me',
            'DB_PASSWORD' => 's3cret',
        ]), '/app');

        self::assertSame(DatabaseDriver::Pgsql, $config->driver);
        self::assertSame(5432, $config->port);
        self::assertSame('pgsql://me@db:5432/garage', $config->describe());
        self::assertStringNotContainsString('s3cret', $config->describe());

        $phinx = $config->toPhinxEnvironment();
        self::assertSame('pgsql', $phinx['adapter']);
        self::assertSame('s3cret', $phinx['pass']);
    }

    public function testMysqlUsesUtf8mb4(): void
    {
        $config = DatabaseConfig::fromEnv(new Env(['DB_DRIVER' => 'mariadb', 'DB_PORT' => '3307']), '/app');

        self::assertSame(DatabaseDriver::Mysql, $config->driver);
        self::assertSame(3307, $config->port);
        self::assertSame('utf8mb4', $config->toPhinxEnvironment()['charset']);
    }

    public function testPrefixSelectsAnIndependentConnection(): void
    {
        $env = new Env(['DB_DRIVER' => 'pgsql', 'TEST_DB_DRIVER' => 'mysql', 'TEST_DB_NAME' => 'logbook_test']);

        $config = DatabaseConfig::fromEnv($env, '/app', 'TEST_DB_');

        self::assertSame(DatabaseDriver::Mysql, $config->driver);
        self::assertSame('logbook_test', $config->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDrivers(): iterable
    {
        yield 'oracle' => ['oci8'];
        yield 'typo' => ['postgress'];
    }

    #[DataProvider('invalidDrivers')]
    public function testRejectsUnsupportedDriver(string $driver): void
    {
        $this->expectException(InvalidArgumentException::class);
        DatabaseConfig::fromEnv(new Env(['DB_DRIVER' => $driver]), '/app');
    }
}
