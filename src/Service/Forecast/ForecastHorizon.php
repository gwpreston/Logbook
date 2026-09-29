<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;

/**
 * The 12 months *Coming up* covers (spec.md §7.18): from the owner's today
 * to the last day of the 11th calendar month after this one. Calendar dates
 * (midnight UTC; see Support\Date\LocalTime).
 */
final readonly class ForecastHorizon
{
    public const int MONTHS = 12;

    private function __construct(
        public DateTimeImmutable $today,
        public DateTimeImmutable $end,
    ) {
    }

    /**
     * @param DateTimeImmutable $today the owner's calendar date (LocalTime::today())
     */
    public static function from(DateTimeImmutable $today): self
    {
        $lastMonth = LocalTime::addMonths(self::firstOfMonth($today), self::MONTHS - 1);

        return new self($today, $lastMonth->setDate(
            (int) $lastMonth->format('Y'),
            (int) $lastMonth->format('n'),
            (int) $lastMonth->format('t'),
        ));
    }

    /**
     * The first day of each month, this one first.
     *
     * @return list<DateTimeImmutable>
     */
    public function months(): array
    {
        $first = self::firstOfMonth($this->today);
        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[] = LocalTime::addMonths($first, $i);
        }

        return $months;
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->today && $date <= $this->end;
    }

    /**
     * Days of the month (its first day) inside the horizon, today included.
     */
    public function daysIn(DateTimeImmutable $month): int
    {
        $start = max($month, $this->today);
        $last = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), (int) $month->format('t'));

        return $last < $start ? 0 : LocalTime::daysBetween($start, $last) + 1;
    }

    /**
     * Index of a date's month in months(), or null outside them.
     */
    public function monthIndex(DateTimeImmutable $date): ?int
    {
        $index = ((int) $date->format('Y') - (int) $this->today->format('Y')) * 12
            + (int) $date->format('n') - (int) $this->today->format('n');

        return $index >= 0 && $index < self::MONTHS ? $index : null;
    }

    public static function firstOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1);
    }
}
