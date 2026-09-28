<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

final readonly class HealthReport
{
    /**
     * @param array<string, HealthStatus> $checks
     * @param string $version the running release (spec.md §8)
     */
    public function __construct(public array $checks, public string $version = '')
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
     * @return array{status: string, version?: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return ['status' => $this->status()->value]
            + ($this->version !== '' ? ['version' => $this->version] : [])
            + ['checks' => array_map(static fn (HealthStatus $s): string => $s->value, $this->checks)];
    }
}
