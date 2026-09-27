<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

use Logbook\Repository\DatabaseStatusRepository;
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
    ) {
    }

    public function run(): HealthReport
    {
        return new HealthReport([
            'app' => HealthStatus::Ok,
            'database' => $this->checkDatabase(),
        ]);
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
