<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Distance a vehicle was driven in a period, from its mileage log (spec.md
 * §7.7): the last reading in the period minus the last one before it, or the
 * first one in it when there is none before. Readings are dated on the
 * owner's calendar, like costs.
 */
final class PeriodDistance
{
    /**
     * @param list<OdometerReading> $readings oldest first
     * @return string|null kilometres (canonical decimal), null when nothing
     *                     was measurably driven
     */
    public static function km(array $readings, ReportPeriod $period, DateTimeZone $zone): ?string
    {
        $before = null;
        $first = null;
        $last = null;
        foreach ($readings as $reading) {
            $day = self::day($reading->recordedAt, $zone);
            if ($period->from !== null && $day < $period->from) {
                $before = $reading;
            } elseif ($day <= $period->to) {
                $first ??= $reading;
                $last = $reading;
            }
        }

        $start = $before ?? $first;
        if ($start === null || $last === null || $start === $last) {
            return null;
        }

        $distance = Decimal::subtract($last->readingKm, $start->readingKm);

        return Decimal::compare($distance, '0') > 0 ? $distance : null;
    }

    /**
     * Whether the mileage series starts on or before $start (the owner's
     * local day of its first reading). Without that, the distance from the
     * first reading on is only part of what was driven since $start, and a
     * cost over the whole of it divided by that part would be too high.
     *
     * @param list<OdometerReading> $readings oldest first
     */
    public static function reachesBack(array $readings, DateTimeImmutable $start, DateTimeZone $zone): bool
    {
        return $readings !== [] && LocalTime::dateOf($readings[0]->recordedAt, $zone) <= $start;
    }

    private static function day(DateTimeImmutable $instant, DateTimeZone $zone): DateTimeImmutable
    {
        $day = LocalTime::parseDate(LocalTime::fromUtc($instant, $zone)->format('Y-m-d'));
        assert($day instanceof DateTimeImmutable);

        return $day;
    }
}
