<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Slim\App;

/**
 * Counts the statements the app sends to the database, for tests that pin
 * how many queries a page costs (a DBAL driver middleware around the app's
 * connection). Every prepared statement, query and exec counts once.
 */
final class QueryCounter implements Middleware
{
    public int $count = 0;

    /**
     * Every statement sent, in order (for finding which query repeats).
     *
     * @var list<string>
     */
    public array $statements = [];

    /**
     * Who sent each statement, by its first words: "Class::method" of the
     * nearest two callers outside the repositories (set before measuring).
     *
     * @var array<string, array<string, int>>
     */
    public array $callers = [];

    public bool $traceCallers = false;

    public function record(string $sql): void
    {
        $this->count++;
        $this->statements[] = $sql;
        if (!$this->traceCallers) {
            return;
        }

        $sites = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            $ours = str_starts_with($class, 'Logbook\\')
                && !str_contains($class, '\\Repository\\')
                && !str_contains($class, 'Tests\\')
                && !str_contains($class, '\\Support\\');
            if (!$ours) {
                continue;
            }
            $sites[] = substr($class, (int) strrpos($class, '\\') + 1) . '::' . $frame['function'];
            if (count($sites) === 3) {
                break;
            }
        }
        $key = substr($sql, 0, 90);
        $site = implode(' < ', $sites);
        $this->callers[$key][$site] = ($this->callers[$key][$site] ?? 0) + 1;
    }

    /**
     * The statements sent while $run ran, how often each, most repeated first.
     *
     * @return array<string, int>
     */
    public function histogram(callable $run): array
    {
        $from = count($this->statements);
        $run();
        $counts = array_count_values(array_slice($this->statements, $from));
        arsort($counts);

        return $counts;
    }

    /**
     * Count the app's queries from now on: its one connection's driver is
     * wrapped in place (no second connection, which SQLite would lock out).
     * Call before anything uses the database.
     *
     * @param App<ContainerInterface> $app
     */
    public static function install(App $app): self
    {
        $container = $app->getContainer();
        assert($container instanceof Container);
        $connection = $container->get(Connection::class);
        assert($connection instanceof Connection);

        $counter = new self();
        $driver = new ReflectionProperty(Connection::class, 'driver');
        $wrapped = $driver->getValue($connection);
        assert($wrapped instanceof Driver);
        $driver->setValue($connection, $counter->wrap($wrapped));

        return $counter;
    }

    /**
     * How many statements $run sends.
     */
    public function during(callable $run): int
    {
        $before = $this->count;
        $run();

        return $this->count - $before;
    }

    public function wrap(Driver $driver): Driver
    {
        $counter = $this;

        return new class ($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->counter->record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->counter->record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
