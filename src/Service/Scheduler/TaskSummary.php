<?php

declare(strict_types=1);

namespace Logbook\Service\Scheduler;

/**
 * What a scheduler pass did, added up from its jobs' counts.
 */
final readonly class TaskSummary
{
    public function __construct(
        public int $users,
        public int $remindersSent,
        public int $digestsSent,
        public int $failures,
        /** AI usage rows past their 90 days, deleted (Phase 26.1). */
        public int $aiRowsDeleted = 0,
        /** Jobs that failed or partly failed (Phase 28.1). */
        public int $jobsFailed = 0,
        /** Another pass held the pass lock; nothing ran. */
        public bool $wasLocked = false,
    ) {
    }

    public static function locked(): self
    {
        return new self(0, 0, 0, 0, wasLocked: true);
    }

    public function failed(): bool
    {
        return $this->failures > 0 || $this->jobsFailed > 0;
    }

    public function describe(): string
    {
        return sprintf(
            '%d account(s) checked, %d reminder(s) sent, %d digest(s) sent, %d failure(s), %d old AI usage row(s) deleted',
            $this->users,
            $this->remindersSent,
            $this->digestsSent,
            $this->failures,
            $this->aiRowsDeleted,
        );
    }
}
