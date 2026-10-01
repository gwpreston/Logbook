<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\NextDue;
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
     * A manual reminder due on a date, at an odometer reading, or both,
     * judged like a schedule, whichever comes first (spec.md §7.6): overdue
     * once the date has passed or the latest reading is past the odometer;
     * due within its lead time in days or the owner's lead distance;
     * upcoming otherwise, and also while an odometer-only reminder has no
     * reading to judge against.
     *
     * @param string|null $currentKm the vehicle's latest reading, km
     * @param float|null $kmPerDay average daily distance (§7.4 projection)
     */
    public static function manual(
        ?DateTimeImmutable $dueOn,
        ?string $dueKm,
        DateTimeImmutable $today,
        int $leadDays,
        ?string $currentKm = null,
        ?float $kmPerDay = null,
        string $leadKm = DueState::SOON_KM,
    ): ManualDue {
        if ($dueKm === null && $dueOn !== null) {
            return new ManualDue(self::statusForDate($dueOn, $today, $leadDays), $dueOn, false);
        }
        $state = DueState::evaluate(new NextDue($dueOn, $dueKm), $today, $currentKm, $kmPerDay, $leadDays, $leadKm);

        return new ManualDue(self::statusForSchedule($state) ?? ReminderStatus::Upcoming, $state->dueOn, $state->projected);
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
