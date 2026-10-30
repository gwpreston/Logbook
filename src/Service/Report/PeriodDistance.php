<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use WeakMap;

/**
 * Distance a vehicle was driven in a period, from its mileage log (spec.md
 * §7.7): the last reading in the period minus the last one before it, or the
 * first one in it when there is none before. Readings are dated on the
 * owner's calendar, like costs. Readings dated before the vehicle's purchase
 * date are an earlier owner's (MOT history brings them in) and are left out
 * (#346); a reading on the purchase day counts.
 */
final class PeriodDistance
{
    /** @var WeakMap<DateTimeImmutable, array<string, DateTimeImmutable>>|null the local day of each instant, by zone */
    private static ?WeakMap $days = null;

    /**
     * @param list<OdometerReading> $readings oldest first
     * @param DateTimeImmutable|null $purchased the vehicle's purchase date, null when unknown
     * @return string|null kilometres (canonical decimal), null when nothing
     *                     was measurably driven
     */
    public static function km(array $readings, ReportPeriod $period, DateTimeZone $zone, ?DateTimeImmutable $purchased): ?string
    {
        return self::span($readings, $period, $zone, $purchased)?->km;
    }

    /**
     * The same distance with the two readings it is the difference of (for
     * the working, spec.md §8).
     *
     * @param list<OdometerReading> $readings oldest first
     */
    public static function span(
        array $readings,
        ReportPeriod $period,
        DateTimeZone $zone,
        ?DateTimeImmutable $purchased,
    ): ?DistanceSpan {
        $owned = self::owned($readings, $purchased, $zone);
        $from = $period->from;
        if (self::inOrder($owned)) {
            // Their days are in order too, so the period is a run of them found by bisection: a page
            // asks for many periods of the same few hundred readings (Phase 54.5, K149).
            $start = $from === null ? 0 : self::leading($owned, static fn (DateTimeImmutable $day): bool => $day < $from, $zone);
            $end = self::leading($owned, static fn (DateTimeImmutable $day): bool => $day <= $period->to, $zone);
            $before = $start > 0 ? $owned[$start - 1] : null;
            $first = $start < $end ? $owned[$start] : null;
            $last = $start < $end ? $owned[$end - 1] : null;
        } else {
            $before = null;
            $first = null;
            $last = null;
            foreach ($owned as $reading) {
                $day = self::day($reading->recordedAt, $zone);
                if ($from !== null && $day < $from) {
                    $before = $reading;
                } elseif ($day <= $period->to) {
                    $first ??= $reading;
                    $last = $reading;
                }
            }
        }

        $start = $before ?? $first;
        if ($start === null || $last === null || $start === $last) {
            return null;
        }

        $distance = Decimal::subtract($last->readingKm, $start->readingKm);
        if (Decimal::compare($distance, '0') <= 0) {
            return null;
        }

        return new DistanceSpan(
            self::day($start->recordedAt, $zone),
            $start->readingKm,
            self::day($last->recordedAt, $zone),
            $last->readingKm,
            $distance,
        );
    }

    /**
     * Whether the mileage series starts on or before $start (the owner's
     * local day of its first reading). Without that, the distance from the
     * first reading on is only part of what was driven since $start, and a
     * cost over the whole of it divided by that part would be too high.
     * Before the purchase date nothing was this owner's to drive, so a start
     * earlier than it is measured from the purchase date.
     *
     * @param list<OdometerReading> $readings oldest first
     */
    public static function reachesBack(
        array $readings,
        DateTimeImmutable $start,
        DateTimeZone $zone,
        ?DateTimeImmutable $purchased,
    ): bool {
        $readings = self::owned($readings, $purchased, $zone);
        if ($readings === []) {
            return false;
        }
        if ($purchased !== null && $purchased > $start) {
            $start = $purchased;
        }

        return LocalTime::dateOf($readings[0]->recordedAt, $zone) <= $start;
    }

    /**
     * The readings from the purchase day on (the owner's calendar day); all of
     * them when the purchase date is unknown.
     *
     * @param list<OdometerReading> $readings oldest first
     * @return list<OdometerReading>
     */
    public static function owned(array $readings, ?DateTimeImmutable $purchased, DateTimeZone $zone): array
    {
        if ($purchased === null) {
            return $readings;
        }

        $from = $purchased->format('Y-m-d');
        if (self::inOrder($readings)) {
            $earlier = self::leading(
                $readings,
                static fn (DateTimeImmutable $day): bool => $day->format('Y-m-d') < $from,
                $zone,
            );

            return array_slice($readings, $earlier);
        }

        return array_values(array_filter(
            $readings,
            static fn (OdometerReading $r): bool => self::day($r->recordedAt, $zone)->format('Y-m-d') >= $from,
        ));
    }

    /**
     * Whether the readings are oldest first, as callers give them: then
     * their days are in order too.
     *
     * @param list<OdometerReading> $readings
     */
    private static function inOrder(array $readings): bool
    {
        for ($i = 1, $n = count($readings); $i < $n; $i++) {
            if ($readings[$i]->recordedAt < $readings[$i - 1]->recordedAt) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many readings at the start have a day for which $holds is true,
     * when it holds for a run at the start and then never: by bisection.
     *
     * @param list<OdometerReading> $readings in order
     * @param callable(DateTimeImmutable): bool $holds
     */
    private static function leading(array $readings, callable $holds, DateTimeZone $zone): int
    {
        $low = 0;
        $high = count($readings);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($holds(self::day($readings[$middle]->recordedAt, $zone))) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The owner's calendar day of an instant. A page asks it of the same
     * readings for many periods, so it is worked out once per instant and
     * zone (the instants are immutable, and the cache goes with them).
     */
    public static function day(DateTimeImmutable $instant, DateTimeZone $zone): DateTimeImmutable
    {
        self::$days ??= new WeakMap();

        $name = $zone->getName();
        $known = self::$days[$instant] ?? [];
        if (!isset($known[$name])) {
            $day = LocalTime::parseCalendarDate(LocalTime::fromUtc($instant, $zone)->format('Y-m-d'));
            assert($day instanceof DateTimeImmutable);
            $known[$name] = $day;
            self::$days[$instant] = $known;
        }

        return $known[$name];
    }
}
