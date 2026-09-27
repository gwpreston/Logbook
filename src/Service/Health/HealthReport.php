<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

final readonly class HealthReport
{
    /**
     * @param array<string, HealthStatus> $checks
     */
    public function __construct(public array $checks)
    {
    }

    public function status(): HealthStatus
    {
        return in_array(HealthStatus::Failing, $this->checks, true) ? HealthStatus::Failing : HealthStatus::Ok;
    }

    public function isHealthy(): bool
    {
        return $this->status() === HealthStatus::Ok;
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'checks' => array_map(static fn (HealthStatus $s): string => $s->value, $this->checks),
        ];
    }
}
