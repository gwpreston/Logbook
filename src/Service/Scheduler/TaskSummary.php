<?php

declare(strict_types=1);

namespace Logbook\Service\Scheduler;

final readonly class TaskSummary
{
    public function __construct(
        public int $users,
        public int $remindersSent,
        public int $digestsSent,
        public int $failures,
        /** AI usage rows past their 90 days, deleted (Phase 26.1). */
        public int $aiRowsDeleted = 0,
    ) {
    }

    public function withAiRowsDeleted(int $count): self
    {
        return new self($this->users, $this->remindersSent, $this->digestsSent, $this->failures, $count);
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
