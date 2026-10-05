<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateInterval;
use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;

/**
 * One period of a vehicle's true cost (spec.md §7.35): *Since bought*,
 * *Last 12 months* (or the 12 before them, for the change) or a calendar
 * year, cut to the time it has been owned. Calendar dates in the owner's
 * time zone, both inclusive.
 */
final readonly class TrueCostPeriod
{
    public function __construct(
        public TrueCostRange $range,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        /** The calendar year, for a year. */
        public ?int $year = null,
        /** Starts after the range would (bought part-way through it). */
        public bool $partialStart = false,
        /** Ends before the range would (today, or the sale). */
        public bool $partialEnd = false,
    ) {
    }

    /**
     * The calendar years an ownership period touches, oldest first.
     *
     * @return list<self>
     */
    public static function years(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $years = [];
        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            $first = $from->setDate($year, 1, 1);
            $last = $from->setDate($year, 12, 31);
            $years[] = new self(
                TrueCostRange::Year,
                $from > $first ? $from : $first,
                $to < $last ? $to : $last,
                $year,
                $from > $first,
                $to < $last,
            );
        }

        return $years;
    }

    /**
     * The reports' *Last 12 months* (this month and the 11 before), or with
     * $before the 12 months before those, cut to the ownership period; null
     * when they do not overlap.
     */
    public static function twelveMonths(
        DateTimeImmutable $today,
        DateTimeImmutable $ownedFrom,
        DateTimeImmutable $ownedTo,
        bool $before = false,
    ): ?self {
        $preset = ReportPeriod::preset(ReportRange::TwelveMonths, $today);
        assert($preset->from !== null);
        $from = $before ? LocalTime::addMonths($preset->from, -12) : $preset->from;
        $to = $before ? $preset->from->sub(new DateInterval('P1D')) : $today;
        $start = $ownedFrom > $from ? $ownedFrom : $from;
        $end = $ownedTo < $to ? $ownedTo : $to;
        if ($start > $end) {
            return null;
        }

        return new self(
            $before ? TrueCostRange::PreviousTwelveMonths : TrueCostRange::TwelveMonths,
            $start,
            $end,
            null,
            $start > $from,
            $end < $to,
        );
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }

    public function days(): int
    {
        return LocalTime::daysBetween($this->from, $this->to) + 1;
    }

    public function isPartial(): bool
    {
        return $this->partialStart || $this->partialEnd;
    }

    public function report(): ReportPeriod
    {
        return new ReportPeriod(ReportRange::Custom, $this->from, $this->to);
    }
}
