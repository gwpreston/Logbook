<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Logbook\Support\Cache\RequestReads;
use SensitiveParameter;

/**
 * Keeps what a page request has read (RequestReads) honest: a statement that
 * writes a table makes the request forget every read that came from it, so
 * nothing in a repository has to remember to. A statement it can't place
 * (DDL, an odd form), and any write to a table that others cascade from,
 * makes it forget everything.
 */
final readonly class ForgetWrittenReadsMiddleware implements Middleware
{
    /** Tables whose rows take others with them (foreign-key cascades). */
    private const array CASCADING = ['vehicles', 'users'];

    public function __construct(private RequestReads $reads)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $reads = $this->reads;

        return new class ($driver, $reads) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly RequestReads $reads)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->reads) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly RequestReads $reads)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        ForgetWrittenReadsMiddleware::forgetFor($this->reads, $sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        ForgetWrittenReadsMiddleware::forgetFor($this->reads, $sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        ForgetWrittenReadsMiddleware::forgetFor($this->reads, $sql);

                        return parent::exec($sql);
                    }

                    /** What was read after a write in the transaction was never committed. */
                    public function rollBack(): void
                    {
                        $this->reads->forgetAll();

                        parent::rollBack();
                    }
                };
            }
        };
    }

    /**
     * Make $reads forget what $sql is about to change.
     */
    public static function forgetFor(RequestReads $reads, string $sql): void
    {
        if (!$reads->isActive()) {
            return;
        }

        $written = self::tableWritten($sql);
        if ($written === null) {
            return;
        }
        if ($written === '*') {
            $reads->forgetAll();

            return;
        }

        $reads->forget($written);
    }

    /**
     * The table $sql writes, null when it only reads, `*` when it can't say.
     */
    public static function tableWritten(string $sql): ?string
    {
        $sql = ltrim($sql);
        $word = strtoupper((string) strtok($sql, " \t\r\n("));
        $readOnly = [
            'SELECT', 'WITH', 'PRAGMA', 'SET', 'SHOW', 'EXPLAIN',
            'BEGIN', 'COMMIT', 'SAVEPOINT', 'RELEASE', 'START',
        ];
        if (in_array($word, $readOnly, true)) {
            return null;
        }

        $found = preg_match(
            '/^(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+[`"\[]?([A-Za-z0-9_]+)/i',
            $sql,
            $match,
        );
        if ($found !== 1) {
            return '*';
        }

        $table = strtolower($match[1]);

        return in_array($table, self::CASCADING, true) ? '*' : $table;
    }
}
