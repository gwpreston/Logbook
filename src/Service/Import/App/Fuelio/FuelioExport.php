<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * One vehicle's Fuelio export, read section by section (spec.md §7.13
 * *Fuelio's format*). Numbers, dates and units stay as written: the import
 * reads them with the units and date order the mapping page settles.
 */
final readonly class FuelioExport
{
    /**
     * @param list<FuelioFill> $fills `Log`
     * @param array<int, string> $categories `CostCategories`: id → name
     * @param list<FuelioCost> $costs `Costs`
     * @param list<FuelioStation> $stations `FavStations`
     * @param list<FuelioPhoto> $photos `Pictures`
     * @param list<string> $unread sections Logbook doesn't read (`Category`, and any new one)
     */
    public function __construct(
        public string $fileName,
        public FuelioVehicle $vehicle,
        /** The `Log` header's distance unit ("mi"), if it names one. */
        public ?string $distanceText,
        /** The `Log` header's volume unit ("litres"), if it names one. */
        public ?string $volumeText,
        /** The `Log` header's consumption unit ("mpg"), if it names one. */
        public ?string $consumptionText,
        public array $fills,
        public array $categories,
        public array $costs,
        public array $stations,
        public array $photos,
        public array $unread,
    ) {
    }

    public function station(string $stationId): ?FuelioStation
    {
        foreach ($this->stations as $station) {
            if ($station->stationId !== '' && $station->stationId === $stationId) {
                return $station;
            }
        }

        return null;
    }

    /**
     * The fuel codes the fill-ups use, in the order they first appear.
     *
     * @return list<int>
     */
    public function fuelCodes(): array
    {
        $codes = [];
        foreach ($this->fills as $fill) {
            $codes[$fill->fuelCode] = true;
        }

        return array_keys($codes);
    }

    /**
     * The category ids the costs use, with the file's categories first.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        $ids = array_fill_keys(array_keys($this->categories), true);
        foreach ($this->costs as $cost) {
            $ids[$cost->typeId] = true;
        }

        return array_keys($ids);
    }
}
