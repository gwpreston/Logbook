<?php

declare(strict_types=1);

namespace Logbook\Service\Odometer;

use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Support\Number\Decimal;

/**
 * Flags implausible readings in a mileage series (spec.md §7.2): each reading
 * is compared with the one before it in time.
 *
 *  - **Backwards:** lower than the previous reading.
 *  - **Jump:** more than MAX_KM_PER_DAY per day since the previous reading
 *    (counting at least one day), e.g. a typo adding a digit. 2,000 km a day
 *    is more than 80 km/h around the clock, so a genuine reading will not
 *    trip it.
 *
 * Pure: no I/O.
 */
final class OdometerPlausibility
{
    public const int MAX_KM_PER_DAY = 2000;

    /**
     * @param list<OdometerReading> $readings oldest first
     * @return array<int, OdometerWarning> by reading id (at most one each)
     */
    public static function check(array $readings): array
    {
        $warnings = [];
        $previous = null;

        foreach ($readings as $reading) {
            if ($previous !== null) {
                $warning = self::compare($previous, $reading);
                if ($warning !== null) {
                    $warnings[$reading->id] = $warning;
                }
            }
            $previous = $reading;
        }

        return $warnings;
    }

    private static function compare(OdometerReading $previous, OdometerReading $reading): ?OdometerWarning
    {
        $distance = Decimal::subtract($reading->readingKm, $previous->readingKm);
        $seconds = $reading->recordedAt->getTimestamp() - $previous->recordedAt->getTimestamp();
        $days = max(1.0, $seconds / 86400);

        if (Decimal::compare($distance, '0') < 0) {
            return new OdometerWarning(OdometerWarning::BACKWARDS, $previous, $distance, $days);
        }
        if ((float) $distance / $days > self::MAX_KM_PER_DAY) {
            return new OdometerWarning(OdometerWarning::JUMP, $previous, $distance, $days);
        }

        return null;
    }
}
