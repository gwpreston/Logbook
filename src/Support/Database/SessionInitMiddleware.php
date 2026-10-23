<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Logbook\Support\Config\DatabaseDriver;
use Pdo\Sqlite;
use SensitiveParameter;

/**
 * Normalises every new database session so the engines behave alike.
 *
 * Documented platform branch (CLAUDE.md §6): DBAL has no portable way to set
 * the session time zone, so the statements below are engine-specific.
 *
 * - PostgreSQL / MySQL: session time zone = UTC, so anything the database
 *   itself computes (NOW(), CURRENT_TIMESTAMP, TIMESTAMP conversion) is UTC,
 *   matching the UTC values the application writes.
 * - SQLite: has no session time zone (it is always UTC); enable foreign-key
 *   enforcement, which is off by default, to match the server engines, and
 *   make LOWER() lower non-ASCII letters as they do (K51).
 */
final readonly class SessionInitMiddleware implements Middleware
{
    public function __construct(private DatabaseDriver $driver)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $engine = $this->driver;

        return new class ($driver, $engine) extends AbstractDriverMiddleware {
            public function __construct(Driver $wrappedDriver, private readonly DatabaseDriver $engine)
            {
                parent::__construct($wrappedDriver);
            }

            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);

                $connection->exec(match ($this->engine) {
                    DatabaseDriver::Pgsql => "SET TIME ZONE 'UTC'",
                    DatabaseDriver::Mysql => "SET time_zone = '+00:00'",
                    DatabaseDriver::Sqlite => 'PRAGMA foreign_keys = ON',
                });
                $native = $connection->getNativeConnection();
                if ($this->engine === DatabaseDriver::Sqlite && $native instanceof Sqlite) {
                    $native->createFunction('LOWER', self::lower(...), 1, Sqlite::DETERMINISTIC);
                }

                return $connection;
            }

            /**
             * SQLite's own LOWER() lowers ASCII only; the server engines lower
             * every letter (K51).
             */
            private static function lower(mixed $value): ?string
            {
                return $value === null ? null : mb_strtolower(is_scalar($value) ? (string) $value : '', 'UTF-8');
            }
        };
    }
}
