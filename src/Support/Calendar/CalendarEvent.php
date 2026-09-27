<?php

declare(strict_types=1);

namespace Logbook\Support\Calendar;

use DateTimeImmutable;

/**
 * An all-day event for ICalendar.
 */
final readonly class CalendarEvent
{
    public function __construct(
        /** Globally unique and stable, so calendar apps update it in place. */
        public string $uid,
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $date,
        public string $summary,
        public string $description = '',
        public ?string $url = null,
        /** Days before to alert (at 09:00); null for no alarm. */
        public ?int $alarmDaysBefore = null,
    ) {
    }
}
