<?php

declare(strict_types=1);

namespace Logbook\Domain\Job;

use DateTimeImmutable;

/**
 * One run of a background job (spec.md §6 JobRun).
 */
final readonly class JobRun
{
    public function __construct(
        public int $id,
        public string $job,
        public JobTrigger $trigger,
        public ?int $userId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public JobStatus $status,
        public ?string $summary,
        public string $output,
        /** Who pressed *Run now*, for display (null: no user, or deleted). */
        public ?string $userName = null,
        /**
         * The result's counts, on the run just made only (not stored).
         *
         * @var array<string, int>
         */
        public array $counts = [],
    ) {
    }

    /**
     * @param array<string, int> $counts
     */
    public function withCounts(array $counts): self
    {
        return new self(
            $this->id,
            $this->job,
            $this->trigger,
            $this->userId,
            $this->startedAt,
            $this->finishedAt,
            $this->status,
            $this->summary,
            $this->output,
            $this->userName,
            $counts,
        );
    }

    /**
     * Seconds the run took, or has taken so far.
     */
    public function seconds(DateTimeImmutable $now): int
    {
        return max(0, ($this->finishedAt ?? $now)->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
