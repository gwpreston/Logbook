<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;

/**
 * The calendar dates a report covers, both inclusive, in the owner's time
 * zone. `from` is null for "all time" until resolved against the earliest
 * cost (see resolve()).
 */
final readonly class ReportPeriod
{
    public function __construct(
        public ReportRange $range,
        /** Calendar date, or null for "from the beginning". */
        public ?DateTimeImmutable $from,
        /** Calendar date. */
        public DateTimeImmutable $to,
    ) {
    }

    /**
     * A preset period ending today (the owner's calendar date). The month
     * presets start on the 1st: "last 12 months" is this month and the 11
     * before it, so every month in it is a whole bar in the chart.
     */
    public static function preset(ReportRange $range, DateTimeImmutable $today): self
    {
        $firstOfMonth = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 1);

        return match ($range) {
            ReportRange::ThisMonth => new self($range, $firstOfMonth, $today),
            ReportRange::ThreeMonths => new self($range, LocalTime::addMonths($firstOfMonth, -2), $today),
            ReportRange::ThisYear => new self($range, $today->setDate((int) $today->format('Y'), 1, 1), $today),
            ReportRange::AllTime => new self($range, null, $today),
            ReportRange::TwelveMonths, ReportRange::Custom => new self(
                ReportRange::TwelveMonths,
                LocalTime::addMonths($firstOfMonth, -11),
                $today,
            ),
        };
    }

    /**
     * From `?range=` (and `from` / `to` for a custom range). Anything
     * unreadable falls back to the default; a custom range typed backwards
     * is turned around, and a missing end means "until today" (a missing
     * start: "from the beginning").
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query, DateTimeImmutable $today): self
    {
        $range = is_string($query['range'] ?? null) ? ReportRange::tryFrom($query['range']) : null;
        if ($range !== ReportRange::Custom) {
            return self::preset($range ?? ReportRange::DEFAULT, $today);
        }

        $from = is_string($query['from'] ?? null) ? LocalTime::parseDate($query['from']) : null;
        $to = is_string($query['to'] ?? null) ? LocalTime::parseDate($query['to']) : null;
        if ($from === null && $to === null) {
            return self::preset(ReportRange::AllTime, $today);
        }
        if ($to === null) {
            $to = $from > $today ? $from : $today;
        }
        if ($from !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(ReportRange::Custom, $from, $to);
    }

    /**
     * With the open start fixed: at $earliest (the first cost), or at the
     * end date when there is nothing at all.
     */
    public function resolve(?DateTimeImmutable $earliest): self
    {
        if ($this->from !== null) {
            return $this;
        }

        return new self($this->range, $earliest !== null && $earliest < $this->to ? $earliest : $this->to, $this->to);
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return ($this->from === null || $date >= $this->from) && $date <= $this->to;
    }

    /**
     * The first day of every calendar month the period touches, oldest first.
     *
     * @return list<DateTimeImmutable>
     */
    public function months(): array
    {
        $start = $this->from ?? $this->to;
        $month = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1);
        $last = $this->to->format('Y-m');

        $months = [];
        while ($month->format('Y-m') <= $last) {
            $months[] = $month;
            $month = LocalTime::addMonths($month, 1);
        }

        return $months;
    }

    /**
     * For links and forms: the query that selects this period again.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        if ($this->range !== ReportRange::Custom) {
            return $this->range === ReportRange::DEFAULT ? [] : ['range' => $this->range->value];
        }

        return array_filter([
            'range' => $this->range->value,
            'from' => $this->from?->format('Y-m-d') ?? '',
            'to' => $this->to->format('Y-m-d'),
        ], static fn (string $value): bool => $value !== '');
    }
}
