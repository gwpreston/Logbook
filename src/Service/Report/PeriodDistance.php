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
 * owner's calendar, like costs. Readings dated before the vehicle's purchase
 * date are an earlier owner's (MOT history brings them in) and are left out
 * (#346); a reading on the purchase day counts.
 */
final class PeriodDistance
{
    /**
     * @param list<OdometerReading> $readings oldest first
     * @param DateTimeImmutable|null $purchased the vehicle's purchase date, null when unknown
     * @return string|null kilometres (canonical decimal), null when nothing
     *                     was measurably driven
     */
    public static function km(array $readings, ReportPeriod $period, DateTimeZone $zone, ?DateTimeImmutable $purchased): ?string
    {
        $before = null;
        $first = null;
        $last = null;
        foreach (self::owned($readings, $purchased, $zone) as $reading) {
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

        return array_values(array_filter(
            $readings,
            static fn (OdometerReading $r): bool => LocalTime::fromUtc($r->recordedAt, $zone)->format('Y-m-d') >= $from,
        ));
    }

    private static function day(DateTimeImmutable $instant, DateTimeZone $zone): DateTimeImmutable
    {
        $day = LocalTime::parseDate(LocalTime::fromUtc($instant, $zone)->format('Y-m-d'));
        assert($day instanceof DateTimeImmutable);

        return $day;
    }
}
