<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Doctrine\DBAL\Connection;

/**
 * Low-level database reachability checks for the health endpoint.
 */
final readonly class DatabaseStatusRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Round-trip a trivial query. Throws if the database is unreachable.
     */
    public function ping(): void
    {
        $this->connection->fetchOne($this->connection->getDatabasePlatform()->getDummySelectSQL());
    }
}
