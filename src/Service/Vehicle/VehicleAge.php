<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Date\LocalTime;

/**
 * How old a vehicle is since first registration (spec.md §7.2), derived on
 * every read. A month is complete on the same day of a later month, clamped
 * to the last day of a shorter month (LocalTime::addMonths), so a vehicle
 * first registered on 29 February turns one on 28 February.
 */
final readonly class VehicleAge
{
    /** The lifetime average needs this much history to mean anything. */
    public const int MIN_DAYS_FOR_AVERAGE = 90;
    private const float DAYS_PER_YEAR = 365.2425;

    public function __construct(
        public int $years,
        /** Months beyond the whole years (0–11). */
        public int $months,
        /** Whole days since first registration. */
        public int $days,
    ) {
    }

    /**
     * The vehicle's age on $today (a calendar date in the owner's time zone,
     * LocalTime::today()), or null without a registration date or when it
     * is after $today.
     */
    public static function of(Vehicle $vehicle, DateTimeImmutable $today): ?self
    {
        $registered = $vehicle->data->firstRegisteredOn;

        return $registered === null || $registered > $today ? null : self::between($registered, $today);
    }

    /**
     * Both are calendar dates (midnight UTC), $registered not after $today.
     */
    public static function between(DateTimeImmutable $registered, DateTimeImmutable $today): self
    {
        $months = ((int) $today->format('Y') - (int) $registered->format('Y')) * 12
            + (int) $today->format('n') - (int) $registered->format('n');
        if ($months > 0 && LocalTime::addMonths($registered, $months) > $today) {
            $months--;
        }
        $months = max(0, $months);

        return new self(intdiv($months, 12), $months % 12, LocalTime::daysBetween($registered, $today));
    }

    /**
     * Translation key for the age as shown ("7 yrs 6 mo", "4 mo", "under
     * 1 mo"), with parameters `years` and `months`.
     */
    public function labelKey(): string
    {
        return match (true) {
            $this->years > 0 && $this->months > 0 => 'vehicle.age.years_months',
            $this->years > 0 => 'vehicle.age.years',
            $this->months > 0 => 'vehicle.age.months',
            default => 'vehicle.age.under_month',
        };
    }

    /**
     * Kilometres per year since first registration, assuming the odometer
     * read about 0 then (true for a new vehicle). Null until the vehicle is
     * MIN_DAYS_FOR_AVERAGE days old or without a reading.
     */
    public function averageKmPerYear(?OdometerReading $latest): ?float
    {
        if ($latest === null || $this->days < self::MIN_DAYS_FOR_AVERAGE) {
            return null;
        }

        return (float) $latest->readingKm / ($this->days / self::DAYS_PER_YEAR);
    }
}
