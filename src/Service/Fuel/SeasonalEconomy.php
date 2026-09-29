<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\MonthlyEconomy;
use Logbook\Domain\Fuel\MonthlyEconomyRow;
use Logbook\Support\Number\Decimal;

/**
 * Economy by month (spec.md §7.3): each segment's distance and volume split
 * across the calendar months it spans, in proportion to the time elapsed
 * between its opening and closing fill-ups. Month boundaries are midnights
 * in the owner's time zone, built with DateTimeImmutable (so a DST change
 * moves the boundary instant, never the proportion's arithmetic); the
 * proportions are real elapsed seconds between instants.
 *
 * Pure: no I/O; "now" and the time zone are passed in.
 */
final class SeasonalEconomy
{
    /** Segments longer than this are too long to place in a season. */
    public const string MAX_SEGMENT = '+92 days';

    public static function of(
        FuelHistory $history,
        EnergyKind $kind,
        DateTimeZone $timeZone,
        DateTimeImmutable $now,
    ): MonthlyEconomy {
        /** @var array<string, array{year: int, month: int, volume: string, distance: string}> $months by "Y-n" */
        $months = [];
        foreach ($history->measured($kind) as $fill) {
            $segment = $fill->segment;
            $opening = $segment?->opening;
            if ($segment === null || $opening === null) {
                continue;
            }
            foreach (self::split($opening->data->filledAt, $segment->endedAt, $timeZone) as [$year, $month, $seconds, $total]) {
                $key = $year . '-' . $month;
                $months[$key] ??= ['year' => $year, 'month' => $month, 'volume' => '0', 'distance' => '0'];
                $volume = self::share($segment->volume, $seconds, $total);
                $distance = self::share($segment->distanceKm, $seconds, $total);
                $months[$key]['volume'] = Decimal::add($months[$key]['volume'], $volume);
                $months[$key]['distance'] = Decimal::add($months[$key]['distance'], $distance);
            }
        }

        $rows = [];
        foreach ($months as $m) {
            $rows[] = new MonthlyEconomyRow($m['year'], $m['month'], $m['volume'], $m['distance']);
        }

        return new MonthlyEconomy($kind, $rows, (int) $now->setTimezone($timeZone)->format('Y'));
    }

    /**
     * The calendar months (owner's time zone) from $start to $end, each with
     * the seconds of the segment inside it and the segment's total seconds.
     * Nothing for a segment longer than MAX_SEGMENT; a segment of no
     * duration falls wholly in its closing month.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}> [year, month, seconds, total]
     */
    public static function split(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeZone $timeZone): array
    {
        $start = $start->setTimezone($timeZone);
        $end = $end->setTimezone($timeZone);
        if ($start->modify(self::MAX_SEGMENT) < $end) {
            return [];
        }

        $total = $end->getTimestamp() - $start->getTimestamp();
        if ($total <= 0) {
            return [[(int) $end->format('Y'), (int) $end->format('n'), 1, 1]];
        }

        $parts = [];
        $monthStart = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)->setTime(0, 0);
        while ($monthStart < $end) {
            $next = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('n') + 1, 1)->setTime(0, 0);
            $from = max($start->getTimestamp(), $monthStart->getTimestamp());
            $to = min($end->getTimestamp(), $next->getTimestamp());
            if ($to > $from) {
                $parts[] = [(int) $monthStart->format('Y'), (int) $monthStart->format('n'), $to - $from, $total];
            }
            $monthStart = $next;
        }

        return $parts;
    }

    /**
     * $amount × $seconds ÷ $total, exactly (6 places).
     */
    private static function share(string $amount, int $seconds, int $total): string
    {
        return $seconds === $total
            ? $amount
            : Decimal::divide(Decimal::multiply($amount, (string) $seconds, 6), (string) $total, 6);
    }
}
