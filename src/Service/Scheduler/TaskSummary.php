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
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            '%d account(s) checked, %d reminder(s) sent, %d digest(s) sent, %d failure(s)',
            $this->users,
            $this->remindersSent,
            $this->digestsSent,
            $this->failures,
        );
    }
}
