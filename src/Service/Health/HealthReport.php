<?php

declare(strict_types=1);

namespace Logbook\Service\Health;

final readonly class HealthReport
{
    /**
     * @param array<string, HealthStatus> $checks
     * @param string $version the running release (spec.md §8)
     * @param array{last_pass: string|null, stale: bool}|null $scheduler the
     *        last scheduler pass (Phase 28.1); never changes the status
     */
    public function __construct(
        public array $checks,
        public string $version = '',
        public ?array $scheduler = null,
    ) {
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
     * @return array{
     *     status: string,
     *     version?: string,
     *     checks: array<string, string>,
     *     scheduler?: array{last_pass: string|null, stale: bool},
     * }
     */
    public function toArray(): array
    {
        return ['status' => $this->status()->value]
            + ($this->version !== '' ? ['version' => $this->version] : [])
            + ['checks' => array_map(static fn (HealthStatus $s): string => $s->value, $this->checks)]
            + ($this->scheduler !== null ? ['scheduler' => $this->scheduler] : []);
    }
}
