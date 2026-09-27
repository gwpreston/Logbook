<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Maintenance\ScheduleState;

/**
 * Which reminders a vehicle's schedules and documents call for today
 * (spec.md §7.6). Pure: the states come in already judged against the
 * owner's today and lead times.
 */
final class ReminderGenerator
{
    /**
     * One reminder per schedule whose next-due point can be judged. Its due
     * date is the sooner of the date limit and the projected distance limit;
     * the occurrence is the stored next-due point, so a projection that
     * drifts day to day is still the same occurrence.
     *
     * @param list<ScheduleState> $states
     * @return list<GeneratedReminder>
     */
    public static function fromSchedules(int $vehicleId, array $states, ReminderPreferences $preferences): array
    {
        $reminders = [];
        foreach ($states as $state) {
            $status = ReminderRules::statusForSchedule($state->due);
            if ($status === null) {
                continue;
            }

            $schedule = $state->schedule;
            $next = $schedule->nextDue;
            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::Schedule,
                sourceId: $schedule->id,
                occurrence: ($next->on?->format('Y-m-d') ?? '') . '|' . ($next->km ?? ''),
                category: $schedule->data->category->value,
                title: $schedule->data->title,
                dueOn: $state->due->dueOn,
                dueKm: $next->km,
                leadTimeDays: $preferences->scheduleDays,
                status: $status,
            );
        }

        return $reminders;
    }

    /**
     * One reminder per current document with an expiry date. A replaced
     * document (a renewal has taken over) raises none.
     *
     * @param list<DocumentState> $states
     * @param DateTimeImmutable $today the owner's calendar date
     * @return list<GeneratedReminder>
     */
    public static function fromDocuments(
        int $vehicleId,
        array $states,
        DateTimeImmutable $today,
        ReminderPreferences $preferences,
    ): array {
        $reminders = [];
        foreach ($states as $state) {
            $data = $state->document->data;
            if (!$state->status->isCurrent() || $data->expiryOn === null) {
                continue;
            }

            $reminders[] = new GeneratedReminder(
                vehicleId: $vehicleId,
                source: ReminderSource::Compliance,
                sourceId: $state->document->id,
                occurrence: $data->expiryOn->format('Y-m-d'),
                category: $data->type->value,
                title: $data->title ?? '',
                dueOn: $data->expiryOn,
                dueKm: null,
                leadTimeDays: $preferences->documentDays,
                status: ReminderRules::statusForDate($data->expiryOn, $today, $preferences->documentDays),
            );
        }

        return $reminders;
    }
}
