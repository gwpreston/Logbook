<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How a reminder is put into words — its name and when it is due — in the
 * current locale and units. One place for the reminder list, notifications
 * and the calendar feed, so they always say the same thing.
 */
final readonly class ReminderWording
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * Its title; an untitled document is named by its type.
     */
    public function name(Reminder $reminder): string
    {
        if ($reminder->title !== '') {
            return $reminder->title;
        }

        return $reminder->source === ReminderSource::Compliance && $reminder->category !== null
            ? $this->translator->trans('compliance.type.' . $reminder->category)
            : $this->translator->trans('reminders.untitled');
    }

    /**
     * "Due in 5 days", "Expired 2 days ago", "Due at 60,000 mi"…
     *
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public function when(Reminder $reminder, DateTimeImmutable $today): string
    {
        $document = $reminder->source === ReminderSource::Compliance;

        if ($reminder->dueOn === null) {
            return $reminder->status === ReminderStatus::Overdue || $reminder->dueKm === null
                ? $this->translator->trans('reminders.when.overdue_now')
                : $this->atOdometer($reminder->dueKm);
        }

        $days = LocalTime::daysBetween($today, $reminder->dueOn);
        if ($days < 0) {
            return $this->translator->trans($document ? 'reminders.when.expired' : 'reminders.when.overdue', ['days' => -$days]);
        }
        if ($reminder->status === ReminderStatus::Overdue) {
            // A schedule past its distance limit before its date.
            return $this->translator->trans('reminders.when.overdue_now');
        }

        return $this->translator->trans($document ? 'reminders.when.expires_in' : 'reminders.when.due_in', ['days' => $days]);
    }

    /**
     * "Due at 60,000 mi" (in the owner's distance unit).
     */
    public function atOdometer(string $km): string
    {
        return $this->translator->trans('reminders.when.at_odometer', ['odometer' => $this->formatter->distance($km)]);
    }

    /**
     * The due date in the owner's format, or '' without one.
     */
    public function date(Reminder $reminder): string
    {
        return $this->formatter->date($reminder->dueOn);
    }

    /**
     * "Due in 5 days (2 Oct 2026)": when, with the date.
     */
    public function whenWithDate(Reminder $reminder, DateTimeImmutable $today): string
    {
        $when = $this->when($reminder, $today);
        $date = $this->date($reminder);

        return $date === '' ? $when : $this->translator->trans('reminders.when.with_date', ['when' => $when, 'date' => $date]);
    }
}
