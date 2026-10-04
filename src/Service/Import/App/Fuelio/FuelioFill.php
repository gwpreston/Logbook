<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * One row of Fuelio's `Log`: a fill-up or charge, values as written.
 */
final readonly class FuelioFill
{
    public function __construct(
        public int $line,
        public string $guid,
        /** Per vehicle; photos point at it. */
        public string $uniqueId,
        /** `Data`: local "yyyy-MM-dd HH:mm" (or the export's date format). */
        public string $date,
        public string $odometer,
        public string $volume,
        public bool $full,
        /** `Price (optional)`: the total paid. */
        public string $total,
        /** `VolumePrice`: the price per unit. */
        public string $pricePerUnit,
        /** Fuelio's own consumption, on the fill-up that starts a segment ('' when none). */
        public string $ownEconomy,
        public string $latitude,
        public string $longitude,
        /** `City (optional)`: "Station - Place". */
        public string $city,
        public string $notes,
        public bool $missed,
        public int $tank,
        public int $fuelCode,
        public string $stationId,
    ) {
    }

    /**
     * The station's name: the part of `City` before " - ".
     */
    public function stationName(): string
    {
        $parts = explode(' - ', $this->city, 2);

        return trim($parts[0]);
    }

    /**
     * @return array{float, float}|null
     */
    public function position(): ?array
    {
        return FuelioReader::position($this->latitude, $this->longitude);
    }
}
