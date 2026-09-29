<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * How long the seller has owned the vehicle and how far it went meanwhile
 * (spec.md §7.19): "Owned since March 2021", or "Owned March 2021 to May
 * 2026" once sold.
 *
 * The distance is the latest reading (up to the sale, once sold) minus the
 * earliest reading on or after the purchase date, by the owner's local
 * date. It is known only when that earliest reading is within MAX_DAYS of
 * the purchase: a first reading months later would understate it, and it
 * is never guessed. Without a purchase date there is no span at all.
 */
final readonly class OwnershipSpan
{
    public const int MAX_DAYS = 31;

    private function __construct(
        /** The purchase date (calendar date). */
        public DateTimeImmutable $from,
        /** The sale date, once sold. */
        public ?DateTimeImmutable $until,
        /** Kilometres covered while owned; null when not known. */
        public ?string $distanceKm,
    ) {
    }

    /**
     * @param list<OdometerReading> $readings the whole series, oldest first
     */
    public static function of(Vehicle $vehicle, array $readings, DateTimeZone $zone): ?self
    {
        $from = $vehicle->data->purchaseDate;
        if ($from === null) {
            return null;
        }
        $until = $vehicle->data->saleDate;

        $first = null;
        $last = null;
        foreach ($readings as $reading) {
            $day = LocalTime::dateOf($reading->recordedAt, $zone);
            if ($day < $from || ($until !== null && $day > $until)) {
                continue;
            }
            $first ??= [$reading, $day];
            $last = $reading;
        }

        $distance = null;
        $near = $first !== null && LocalTime::daysBetween($from, $first[1]) <= self::MAX_DAYS;
        if ($near && $last !== null && $last !== $first[0]) {
            $distance = Decimal::subtract($last->readingKm, $first[0]->readingKm);
            if (Decimal::compare($distance, '0') < 0) {
                $distance = null;
            }
        }

        return new self($from, $until, $distance);
    }

    public function isSold(): bool
    {
        return $this->until !== null;
    }
}
