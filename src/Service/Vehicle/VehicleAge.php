<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
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
     * The lifetime average (spec.md §7.2): kilometres per year from first
     * registration to the date of $latest (its recorded_at as a local date
     * in $zone), not to today, so a reading dated months back, or a vehicle
     * not driven for a while, is not understated. Null without a
     * registration date or a reading, for a reading dated before first
     * registration, and until the vehicle was MIN_DAYS_FOR_AVERAGE days old
     * at that reading.
     */
    public static function lifetimeAverageKmPerYear(Vehicle $vehicle, ?OdometerReading $latest, DateTimeZone $zone): ?float
    {
        if ($latest === null) {
            return null;
        }

        return self::of($vehicle, LocalTime::dateOf($latest->recordedAt, $zone))?->averageKmPerYear($latest);
    }

    /**
     * Kilometres per year over this age, assuming the odometer read about 0
     * at first registration (true for a new vehicle). This age must be the
     * one at $latest (lifetimeAverageKmPerYear()). Null until the vehicle is
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
