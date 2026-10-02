<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * One price paid at a station, for the price history (spec.md §7.33).
 */
final readonly class PricePoint
{
    public function __construct(
        public DateTimeImmutable $filledAt,
        public Fuel $fuel,
        public ?FuelGrade $grade,
        public string $currency,
        /** Per litre or kWh. */
        public string $price,
        public int $vehicleId,
    ) {
    }
}
