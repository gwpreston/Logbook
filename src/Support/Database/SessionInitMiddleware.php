<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Logbook\Support\Config\DatabaseDriver;
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
 *   enforcement, which is off by default, to match the server engines.
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

                return $connection;
            }
        };
    }
}
