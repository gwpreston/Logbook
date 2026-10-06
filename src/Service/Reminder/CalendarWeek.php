<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

/**
 * A week (a row) of a reminders calendar, with its number in the viewer's
 * locale (spec.md §7.6 *Calendar view*, #211).
 */
final readonly class CalendarWeek
{
    /**
     * @param list<CalendarDay> $days seven, from the locale's first day of the week
     */
    public function __construct(
        public int $number,
        public array $days,
    ) {
    }

    public function hasItems(): bool
    {
        foreach ($this->days as $day) {
            if ($day->hasItems()) {
                return true;
            }
        }

        return false;
    }
}
