<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Incident\Incident;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Valuation\VehicleValuation;

/**
 * What a hideable check judged, as a SHA-256 (spec.md §7.24 *Hiding*). A
 * hidden check stays hidden only while its fingerprint still matches, so a
 * change to what it judged brings it back. Pure.
 */
final class Fingerprint
{
    /**
     * A flagged reading with the readings either side of it: editing any of
     * the three, or adding one between them, changes it.
     */
    public static function reading(?OdometerReading $previous, OdometerReading $reading, ?OdometerReading $next): string
    {
        return self::hash(['reading', self::point($previous), self::point($reading), self::point($next)]);
    }

    /**
     * The latest reading (or none): a new or edited latest reading changes it.
     */
    public static function mileage(?OdometerReading $latest): string
    {
        return self::hash(['mileage', self::point($latest)]);
    }

    public static function valuation(VehicleValuation $latest): string
    {
        return self::hash([
            'valuation',
            $latest->id,
            $latest->data->valuedOn->format('Y-m-d'),
            $latest->data->amount,
        ]);
    }

    /**
     * A waiting claim's status and latest update: news, or a new status, re-judges.
     */
    public static function claim(Incident $incident): string
    {
        return self::hash([
            'claim',
            $incident->id,
            $incident->data->claim->status->value,
            $incident->data->claim->updatedOn?->format('Y-m-d'),
        ]);
    }

    /**
     * The recent segments' closing fill-ups: a new tank re-judges.
     */
    public static function drift(DriftFinding $finding): string
    {
        return self::hash(['drift', $finding->kind->value, $finding->closingIds]);
    }

    /**
     * A fill-up's price, volume and total: an edit re-judges.
     */
    public static function price(FuelEntry $entry): string
    {
        return self::hash(['price', $entry->id, $entry->data->pricePerUnit, $entry->data->volume, $entry->data->totalCost]);
    }

    /**
     * A maintenance record's cost and category.
     */
    public static function cost(MaintenanceEntry $entry): string
    {
        return self::hash(['cost', $entry->id, $entry->data->cost, $entry->data->category->value]);
    }

    /**
     * @return list<int|string>|string
     */
    private static function point(?OdometerReading $reading): array|string
    {
        return $reading === null
            ? 'none'
            : [$reading->id, $reading->readingKm, $reading->recordedAt->getTimestamp()];
    }

    /**
     * @param list<mixed> $parts
     */
    private static function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
