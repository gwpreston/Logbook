<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

use DateTimeZone;
use Logbook\Kernel;
use Logbook\Repository\DatabaseStatusRepository;
use Logbook\Service\Jobs\SchedulerHealth;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates the liveness checks behind GET /health.
 */
final readonly class HealthCheck
{
    public function __construct(
        private DatabaseStatusRepository $database,
        private LoggerInterface $logger,
        private SchedulerHealth $scheduler,
    ) {
    }

    public function run(): HealthReport
    {
        $database = $this->checkDatabase();

        return new HealthReport([
            'app' => HealthStatus::Ok,
            'database' => $database,
        ], Kernel::version(), $database === HealthStatus::Ok ? $this->scheduler() : null);
    }

    /**
     * The last scheduler pass, for monitoring (spec.md §7.30). A table not
     * migrated yet reads as never run.
     *
     * @return array{last_pass: string|null, stale: bool}
     */
    private function scheduler(): array
    {
        try {
            $last = $this->scheduler->lastPassAt();

            return [
                'last_pass' => $last?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
                'stale' => $this->scheduler->isStale(),
            ];
        } catch (Throwable) {
            return ['last_pass' => null, 'stale' => true];
        }
    }

    private function checkDatabase(): HealthStatus
    {
        try {
            $this->database->ping();

            return HealthStatus::Ok;
        } catch (Throwable $e) {
            // Details go to the log only; the endpoint is unauthenticated.
            $this->logger->warning('Health check: database unreachable', ['exception' => $e]);

            return HealthStatus::Failing;
        }
    }
}
