<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Support\Date\LocalTime;

/**
 * *Mileage not updated* (spec.md §7.24): only for a vehicle whose
 * projections need readings (a distance-based schedule, or a fitted tyre
 * with a wear estimate), when its latest reading is more than the owner's
 * threshold of days old, counted in the owner's time zone. A vehicle with
 * no reading at all counts as not updated. Pure.
 */
final class StaleMileage
{
    /**
     * @param bool $needsReadings the vehicle has something projected by distance
     * @param DateTimeImmutable $today the owner's calendar date
     * @param DateTimeZone $zone the owner's time zone
     */
    public static function isStale(
        ?OdometerReading $latest,
        bool $needsReadings,
        DateTimeImmutable $today,
        DateTimeZone $zone,
        int $days,
    ): bool {
        if (!$needsReadings) {
            return false;
        }
        if ($latest === null) {
            return true;
        }

        return LocalTime::daysBetween(LocalTime::dateOf($latest->recordedAt, $zone), $today) > $days;
    }
}
