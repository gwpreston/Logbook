<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Date\LocalTime;

/**
 * A reminder with its vehicle, for lists and notifications.
 */
final readonly class ReminderEntry
{
    public function __construct(
        public Reminder $reminder,
        public Vehicle $vehicle,
    ) {
    }

    /**
     * Days from $today to the due date (negative when overdue); null without a date.
     */
    public function daysLeft(DateTimeImmutable $today): ?int
    {
        return $this->reminder->dueOn === null ? null : LocalTime::daysBetween($today, $this->reminder->dueOn);
    }
}
