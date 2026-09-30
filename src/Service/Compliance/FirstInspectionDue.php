<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Reminder\ReminderRules;
use Logbook\Support\Date\LocalTime;

/**
 * A vehicle's *First MOT due* date while it has no inspection document
 * (spec.md §7.5), judged against the owner's today and document lead time
 * as its reminder is.
 */
final readonly class FirstInspectionDue
{
    public function __construct(
        public DateTimeImmutable $dueOn,
        /** Days from today; negative once overdue. */
        public int $daysLeft,
        /** Upcoming, due (within the lead time) or overdue. */
        public ReminderStatus $status,
    ) {
    }

    /**
     * @param DateTimeImmutable $today the owner's calendar date
     * @param int $leadDays the owner's document lead time
     */
    public static function on(DateTimeImmutable $dueOn, DateTimeImmutable $today, int $leadDays): self
    {
        return new self($dueOn, LocalTime::daysBetween($today, $dueOn), ReminderRules::statusForDate($dueOn, $today, $leadDays));
    }
}
