<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * One row of Fuelio's `FavStations`: a public place, so its position is
 * kept (spec.md §7.13).
 */
final readonly class FuelioStation
{
    public function __construct(
        public int $line,
        public string $guid,
        public string $name,
        public string $latitude,
        public string $longitude,
        public string $stationId,
        /** Usually the place, e.g. "Carrickfergus, Seapoint". */
        public string $description,
        /** Three letters, e.g. "GBR". */
        public string $countryCode,
    ) {
    }

    /**
     * @return array{float, float}|null
     */
    public function position(): ?array
    {
        return FuelioReader::position($this->latitude, $this->longitude);
    }
}
