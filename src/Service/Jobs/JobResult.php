<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Domain\Job\JobStatus;

/**
 * How a job's run went: ok, partial or failed, a one-line summary (in the
 * language of whoever ran it) and the counts behind it.
 */
final readonly class JobResult
{
    /**
     * @param array<string, int> $counts
     */
    private function __construct(
        public JobStatus $status,
        public string $summary,
        public array $counts,
    ) {
    }

    /**
     * @param array<string, int> $counts
     */
    public static function ok(string $summary, array $counts = []): self
    {
        return new self(JobStatus::Ok, $summary, $counts);
    }

    /**
     * @param array<string, int> $counts
     */
    public static function partial(string $summary, array $counts = []): self
    {
        return new self(JobStatus::Partial, $summary, $counts);
    }

    /**
     * @param array<string, int> $counts
     */
    public static function failed(string $summary, array $counts = []): self
    {
        return new self(JobStatus::Failed, $summary, $counts);
    }

    public function count(string $name): int
    {
        return $this->counts[$name] ?? 0;
    }
}
