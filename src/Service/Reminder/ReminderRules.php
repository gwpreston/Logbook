<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Service\Maintenance\DueState;
use Logbook\Service\Maintenance\DueStatus;
use Logbook\Service\Tyre\TyreVerdict;
use Logbook\Support\Date\LocalTime;

/**
 * How a reminder's status follows from today (spec.md §7.6). All dates are
 * calendar dates; "today" is the owner's (LocalTime::today()).
 */
final class ReminderRules
{
    /**
     * Overdue once the date has passed; due within the lead time (the day
     * itself included); upcoming before that.
     */
    public static function statusForDate(DateTimeImmutable $dueOn, DateTimeImmutable $today, int $leadDays): ReminderStatus
    {
        $daysLeft = LocalTime::daysBetween($today, $dueOn);

        return match (true) {
            $daysLeft < 0 => ReminderStatus::Overdue,
            $daysLeft <= $leadDays => ReminderStatus::Due,
            default => ReminderStatus::Upcoming,
        };
    }

    /**
     * A schedule's due state (already judged against the lead time and lead
     * distance) as a reminder status; null when it cannot be judged.
     */
    public static function statusForSchedule(DueState $due): ?ReminderStatus
    {
        return match ($due->status) {
            DueStatus::Overdue => ReminderStatus::Overdue,
            DueStatus::Soon => ReminderStatus::Due,
            DueStatus::Ok => ReminderStatus::Upcoming,
            DueStatus::Unknown => null,
        };
    }

    /**
     * A vehicle's tyres judged as one (TyreJudgement, against the schedule
     * lead time and distance) as a reminder status; null when nothing is
     * judgeable.
     */
    public static function statusForTyres(TyreVerdict $verdict): ?ReminderStatus
    {
        return match ($verdict->status) {
            DueStatus::Overdue => ReminderStatus::Overdue,
            DueStatus::Soon => ReminderStatus::Due,
            DueStatus::Ok => ReminderStatus::Upcoming,
            DueStatus::Unknown => null,
        };
    }

    /**
     * The status a reminder moves to: the owner's dismissed / done stick;
     * otherwise the one worked out for today.
     */
    public static function next(ReminderStatus $stored, ReminderStatus $computed): ReminderStatus
    {
        return $stored->isClosed() ? $stored : $computed;
    }
}
