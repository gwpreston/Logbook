<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Builds Fuelio CSV exports in the confirmed format (spec.md §7.13
 * *Fuelio's format*) for what the owner's sample doesn't cover: other
 * units, fuels, categories and flags. Only the columns the importer reads
 * have to be given; the rest of the confirmed header is filled in.
 */
final class FuelioCsv
{
    private const array VEHICLE = [
        'Name', 'Description', 'DistUnit', 'FuelUnit', 'ConsumptionUnit', 'ImportCSVDateFormat', 'VIN', 'Insurance',
        'Plate', 'Make', 'Model', 'Year', 'TankCount', 'Tank1Type', 'Tank2Type', 'Active', 'Tank1Capacity',
        'Tank2Capacity', 'FuelUnitTank2', 'FuelConsumptionTank2', 'guid', 'lastupdated',
    ];
    private const array COSTS = [
        'CostTitle', 'Date', 'Odo', 'CostTypeID', 'Notes', 'Cost', 'flag', 'idR', 'read', 'RemindOdo', 'RemindDate',
        'isTemplate', 'RepeatOdo', 'RepeatMonths', 'isIncome', 'UniqueId', 'guid', 'lastupdated',
    ];
    private const array CATEGORIES = ['CostTypeID', 'Name', 'priority', 'color', 'guid', 'lastupdated'];
    private const array STATIONS = [
        'NameBrand', 'Latitude', 'Longitude', 'StationID', 'Description', 'CountryCode', 'guid', 'lastupdated',
    ];

    /** @var array<string, string> */
    private array $vehicle = [
        'Name' => 'Test car', 'Make' => 'Skoda', 'Model' => 'Octavia', 'Year' => '2019', 'Plate' => 'XY19 ZZZ',
        'ImportCSVDateFormat' => 'yyyy-MM-dd', 'TankCount' => '1', 'Tank1Type' => '100', 'Tank1Capacity' => '50.0',
        'DistUnit' => '0', 'FuelUnit' => '0', 'ConsumptionUnit' => '0',
    ];
    /** @var list<array<string, string>> */
    private array $fills = [];
    /** @var list<array<string, string>> */
    private array $costs = [];
    /** @var array<int, string> */
    private array $categories = [
        1 => 'Service', 2 => 'Maintenance', 4 => 'Registration', 5 => 'Parking', 6 => 'Wash',
        7 => 'Tolls', 8 => 'Tickets/Fines', 9 => 'Tuning', 31 => 'Insurance',
    ];
    /** @var list<array<string, string>> */
    private array $stations = [];
    private int $id = 0;

    public function __construct(
        private string $distance = 'km',
        private string $volume = 'litres',
        private string $consumption = 'l/100km',
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function vehicle(array $values): self
    {
        $this->vehicle = $values + $this->vehicle;

        return $this;
    }

    /**
     * A fill-up: date, odometer, volume, total and price per unit, plus any
     * other `Log` column by its short name (Full, Missed, FuelType,
     * TankNumber, City, StationID, latitude, longitude, own, guid).
     *
     * @param array<string, string> $more
     */
    public function fill(string $date, string $odometer, string $volume, string $total, string $price, array $more = []): self
    {
        $this->fills[] = [
            'Data' => $date, 'Odo' => $odometer, 'Fuel' => $volume, 'Price' => $total, 'VolumePrice' => $price,
            'UniqueId' => (string) (++$this->id), 'guid' => $more['guid'] ?? self::guid('fill', $this->id),
        ] + $more + ['Full' => '1', 'Missed' => '0', 'TankNumber' => '1', 'FuelType' => '110'];

        return $this;
    }

    /**
     * @param array<string, string> $more any other `Costs` column
     */
    public function cost(string $title, string $date, string $odometer, int $type, string $cost, array $more = []): self
    {
        $this->costs[] = [
            'CostTitle' => $title, 'Date' => $date, 'Odo' => $odometer, 'CostTypeID' => (string) $type, 'Cost' => $cost,
            'UniqueId' => (string) (++$this->id), 'guid' => $more['guid'] ?? self::guid('cost', $this->id),
        ] + $more + [
            'isTemplate' => '0', 'isIncome' => '0', 'RepeatOdo' => '0', 'RepeatMonths' => '0', 'RemindDate' => '2011-01-01',
        ];

        return $this;
    }

    public function category(int $id, string $name): self
    {
        $this->categories[$id] = $name;

        return $this;
    }

    public function station(string $name, string $lat, string $lon, string $id, string $place = '', string $country = 'GBR'): self
    {
        $this->stations[] = [
            'NameBrand' => $name, 'Latitude' => $lat, 'Longitude' => $lon, 'StationID' => $id,
            'Description' => $place, 'CountryCode' => $country, 'guid' => self::guid('station', ++$this->id),
        ];

        return $this;
    }

    public function build(): string
    {
        $log = [
            'Data', sprintf('Odo (%s)', $this->distance), sprintf('Fuel (%s)', $this->volume), 'Full', 'Price (optional)',
            sprintf('%s (optional)', $this->consumption), 'latitude (optional)', 'longitude (optional)', 'City (optional)',
            'Notes (optional)', 'Missed', 'TankNumber', 'FuelType', 'VolumePrice', 'StationID (optional)', 'ExcludeDistance',
            'UniqueId', 'TankCalc', 'Weather', 'guid', 'lastupdated',
        ];
        $short = static fn (string $column): string => (string) preg_replace('/ \(.*$/', '', $column);
        $lines = [
            self::line(['## Vehicle']),
            self::line(self::VEHICLE),
            self::line(array_map(fn (string $c): string => $this->vehicle[$c] ?? '', self::VEHICLE)),
        ];
        $lines[] = self::line(['## Log']);
        $lines[] = self::line($log);
        foreach (array_reverse($this->fills) as $fill) {
            // Fuelio's own consumption column is named by its unit; `own` fills it.
            $own = $short($log[5]);
            $lines[] = self::line(array_map(
                static fn (string $c): string => $fill[$short($c)] ?? ($short($c) === $own ? ($fill['own'] ?? '') : ''),
                $log,
            ));
        }
        $lines[] = self::line(['## CostCategories']);
        $lines[] = self::line(self::CATEGORIES);
        foreach ($this->categories as $id => $name) {
            $lines[] = self::line([(string) $id, $name, '0', '', self::guid('category', $id), '1']);
        }
        $lines[] = self::line(['## Costs']);
        $lines[] = self::line(self::COSTS);
        foreach ($this->costs as $cost) {
            $lines[] = self::line(array_map(static fn (string $c): string => $cost[$c] ?? '', self::COSTS));
        }
        $lines[] = self::line(['## FavStations']);
        $lines[] = self::line(self::STATIONS);
        foreach ($this->stations as $station) {
            $lines[] = self::line(array_map(static fn (string $c): string => $station[$c] ?? '', self::STATIONS));
        }
        $lines[] = self::line(['## Category']);
        $lines[] = self::line(['IdCategory', 'Name', 'guid', 'lastupdated']);
        $lines[] = self::line(['1', 'Private', self::guid('trip', 1), '1']);

        return implode("\n", $lines) . "\n";
    }

    public static function guid(string $kind, int $n): string
    {
        return sprintf('%08x-0000-4000-a000-%012d', crc32($kind), $n);
    }

    /**
     * @param list<string> $values
     */
    private static function line(array $values): string
    {
        $quote = static fn (string $v): string => $v === '' ? '' : '"' . str_replace('"', '""', $v) . '"';

        return implode(',', array_map($quote, $values));
    }
}
