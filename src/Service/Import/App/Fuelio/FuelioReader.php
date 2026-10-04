<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

use Logbook\Service\Import\App\AppFile;
use Logbook\Service\Import\App\AppSection;

/**
 * Recognises a Fuelio export and reads its sections into typed rows
 * (spec.md §7.13 *Fuelio's format*, confirmed from a real 2026 export).
 */
final class FuelioReader
{
    public const string APP = 'fuelio';

    /** Sections read; any other is listed as not read. */
    private const array READ = ['Vehicle', 'Log', 'CostCategories', 'Costs', 'FavStations', 'Pictures'];

    /** A `Log` header naming a consumption unit: Fuelio's own figure per tank. */
    private const string CONSUMPTION_UNITS = '/^(mpg|l\/100\s?km|km\/l|mi\/kwh|kwh\/100\s?km|km\/kwh'
        . '|kg\/100\s?km|km\/kg|mi\/kg)\b/i';

    /**
     * A Fuelio export has a `Vehicle` section and a `Log` whose header has
     * its date and odometer columns.
     */
    public static function detect(AppFile $file): bool
    {
        $log = $file->section('Log');

        return $file->section('Vehicle') !== null
            && $log !== null
            && in_array('Data', $log->header, true)
            && $log->column('Odo') !== null;
    }

    public static function read(AppFile $file): FuelioExport
    {
        $log = $file->section('Log') ?? new AppSection('Log', [], []);
        $volumeColumn = $log->column('Fuel (') ?? (in_array('Fuel', $log->header, true) ? 'Fuel' : '');
        $consumptionColumn = self::consumptionColumn($log);

        $fills = [];
        foreach ($log->rows as $row) {
            $c = $row['cells'];
            $fills[] = new FuelioFill(
                line: $row['line'],
                guid: $c['guid'] ?? '',
                uniqueId: $c['UniqueId'] ?? '',
                date: $c['Data'] ?? '',
                odometer: $c[$log->column('Odo') ?? ''] ?? '',
                volume: $c[$volumeColumn] ?? '',
                full: ($c['Full'] ?? '1') !== '0',
                total: $c[$log->column('Price') ?? ''] ?? '',
                pricePerUnit: $c['VolumePrice'] ?? '',
                ownEconomy: $consumptionColumn === null ? '' : ($c[$consumptionColumn] ?? ''),
                latitude: $c[$log->column('latitude') ?? ''] ?? '',
                longitude: $c[$log->column('longitude') ?? ''] ?? '',
                city: $c[$log->column('City') ?? ''] ?? '',
                notes: $c[$log->column('Notes') ?? ''] ?? '',
                missed: ($c['Missed'] ?? '0') === '1',
                tank: self::int($c['TankNumber'] ?? '1', 1),
                fuelCode: self::int($c['FuelType'] ?? '0', 0),
                stationId: self::id($c[$log->column('StationID') ?? ''] ?? ''),
            );
        }

        $categories = [];
        foreach ($file->section('CostCategories')->rows ?? [] as $row) {
            $id = self::int($row['cells']['CostTypeID'] ?? '', -1);
            if ($id >= 0) {
                $categories[$id] = $row['cells']['Name'] ?? '';
            }
        }

        $costs = [];
        foreach ($file->section('Costs')->rows ?? [] as $row) {
            $c = $row['cells'];
            $costs[] = new FuelioCost(
                line: $row['line'],
                guid: $c['guid'] ?? '',
                uniqueId: $c['UniqueId'] ?? '',
                title: $c['CostTitle'] ?? '',
                date: $c['Date'] ?? '',
                odometer: $c['Odo'] ?? '',
                typeId: self::int($c['CostTypeID'] ?? '', 0),
                notes: $c['Notes'] ?? '',
                cost: $c['Cost'] ?? '',
                isTemplate: ($c['isTemplate'] ?? '0') === '1',
                isIncome: ($c['isIncome'] ?? '0') === '1',
                repeatOdometer: $c['RepeatOdo'] ?? '0',
                repeatMonths: self::int($c['RepeatMonths'] ?? '0', 0),
            );
        }

        $stations = [];
        foreach ($file->section('FavStations')->rows ?? [] as $row) {
            $c = $row['cells'];
            $stations[] = new FuelioStation(
                line: $row['line'],
                guid: $c['guid'] ?? '',
                name: $c['NameBrand'] ?? '',
                latitude: $c['Latitude'] ?? '',
                longitude: $c['Longitude'] ?? '',
                stationId: self::id($c['StationID'] ?? ''),
                description: $c['Description'] ?? '',
                countryCode: $c['CountryCode'] ?? '',
            );
        }

        $photos = [];
        foreach ($file->section('Pictures')->rows ?? [] as $row) {
            $c = $row['cells'];
            $photos[] = new FuelioPhoto(
                line: $row['line'],
                guid: $c['guid'] ?? '',
                filename: basename(str_replace('\\', '/', $c['Filename'] ?? '')),
                type: self::int($c['Type'] ?? '', 0),
                targetId: $c['target_id'] ?? '',
            );
        }

        $vehicle = $file->section('Vehicle')->rows[0]['cells'] ?? [];

        return new FuelioExport(
            fileName: $file->name,
            vehicle: new FuelioVehicle(
                name: $vehicle['Name'] ?? '',
                make: $vehicle['Make'] ?? '',
                model: $vehicle['Model'] ?? '',
                year: ($y = self::int($vehicle['Year'] ?? '', 0)) >= 1900 && $y <= 2100 ? $y : null,
                plate: $vehicle['Plate'] ?? '',
                vin: $vehicle['VIN'] ?? '',
                dateFormat: $vehicle['ImportCSVDateFormat'] ?? '',
                tankCount: self::int($vehicle['TankCount'] ?? '1', 1),
                tank1Type: self::int($vehicle['Tank1Type'] ?? '0', 0),
                tank2Type: self::int($vehicle['Tank2Type'] ?? '0', 0),
                tank1Capacity: self::positive($vehicle['Tank1Capacity'] ?? ''),
            ),
            distanceText: $log->unitOf('Odo'),
            volumeText: $log->unitOf('Fuel ('),
            consumptionText: $consumptionColumn === null
                ? null
                : trim((string) preg_replace('/\(optional\)/i', '', $consumptionColumn)),
            fills: $fills,
            categories: $categories,
            costs: $costs,
            stations: $stations,
            photos: $photos,
            unread: array_values(array_diff(array_keys($file->sections), self::READ)),
        );
    }

    /**
     * A position from two decimal texts, or null when either is missing,
     * out of range or exactly 0, 0 (Fuelio's "no position").
     *
     * @return array{float, float}|null
     */
    public static function position(string $latitude, string $longitude): ?array
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        $lat = (float) $latitude;
        $lon = (float) $longitude;
        if (abs($lat) > 90.0 || abs($lon) > 180.0 || ($lat === 0.0 && $lon === 0.0)) {
            return null;
        }

        return [$lat, $lon];
    }

    /**
     * The `Log` column holding Fuelio's own consumption: the one after the
     * price, named by its unit ("mpg (optional)", "l/100km (optional)").
     */
    private static function consumptionColumn(AppSection $log): ?string
    {
        foreach ($log->header as $name) {
            if (preg_match(self::CONSUMPTION_UNITS, $name) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function int(string $value, int $default): int
    {
        return preg_match('/^-?\d+(\.0+)?$/', trim($value)) === 1 ? (int) $value : $default;
    }

    /**
     * An id as text; '' for none ("0", "-1" or blank).
     */
    private static function id(string $value): string
    {
        $value = trim($value);

        return $value === '' || $value === '0' || $value === '-1' ? '' : $value;
    }

    private static function positive(string $value): string
    {
        return is_numeric($value) && (float) $value > 0.0 ? trim($value) : '';
    }
}
