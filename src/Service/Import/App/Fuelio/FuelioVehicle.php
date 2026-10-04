<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * Fuelio's `Vehicle` row. Its `guid` changes on every export, so it is
 * never used as a key (spec.md §7.13).
 */
final readonly class FuelioVehicle
{
    public function __construct(
        public string $name,
        public string $make,
        public string $model,
        public ?int $year,
        public string $plate,
        public string $vin,
        /** `ImportCSVDateFormat`, e.g. "yyyy-MM-dd". */
        public string $dateFormat,
        public int $tankCount,
        public int $tank1Type,
        public int $tank2Type,
        /** Tank 1's capacity as written (litres for the sample), or '' when none. */
        public string $tank1Capacity,
    ) {
    }
}
