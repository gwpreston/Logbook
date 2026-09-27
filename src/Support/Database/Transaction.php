<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Doctrine\DBAL\Connection;

/**
 * Runs a unit of work atomically, so services can keep related rows (a fill-up
 * and the odometer reading it creates) consistent without handling the
 * connection themselves.
 */
final readonly class Transaction
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }
}
