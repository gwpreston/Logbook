<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Support\Number\Decimal;

/**
 * Derives consumption and cost figures from a vehicle's fill-ups.
 *
 * Consumption is only ever measured **full to full**: the fuel burned between
 * two full fills is everything bought after the first one, partial top-ups
 * included, up to and including the second. Dividing each fill's volume by
 * the distance since the previous fill (the classic wrong-number bug) is
 * never done.
 *
 *  - The first full fill is a baseline: the fuel it adds was burned before it.
 *  - A partial fill joins the segment that the next full fill closes.
 *  - A fill flagged "missed previous" throws away the open segment (unknown
 *    fuel went in), so a missing receipt cannot inflate the figures; if it is
 *    a full fill, measuring restarts from it.
 *  - A full fill whose odometer is not beyond the segment start cannot be
 *    measured; measuring restarts from it.
 *  - Liquid fuel (litres) and electricity (kWh) are separate series.
 *  - Grades never split a series. Each segment only records the grade that
 *    was burned over it: the opening full fill's, when every partial inside
 *    shares it (EconomySegment::$grade); GradeStatistics reads that.
 *
 * Pure: no I/O, so every rule is unit-tested with worked examples.
 */
final class FuelEconomy
{
    /**
     * @param list<FuelEntry> $entries in the order they happened
     */
    public static function analyse(array $entries): FuelHistory
    {
        /** @var array<int, FillEconomy> $results by position in $entries */
        $results = [];
        $summaries = [];

        foreach (EnergyKind::cases() as $kind) {
            $positions = array_keys(array_filter(
                $entries,
                static fn (FuelEntry $entry): bool => $entry->data->fuel->kind() === $kind,
            ));
            if ($positions === []) {
                continue;
            }

            $series = self::series(array_map(static fn (int $i): FuelEntry => $entries[$i], $positions));
            foreach ($positions as $index => $position) {
                $results[$position] = $series[$index];
            }
            $summaries[$kind->value] = self::summarise($kind, $series);
        }

        ksort($results);

        return new FuelHistory(array_values($results), $summaries);
    }

    /**
     * @param list<FuelEntry> $entries one kind, in order
     * @return list<FillEconomy>
     */
    private static function series(array $entries): array
    {
        $results = [];
        $anchor = null;       // the full fill the open segment started from
        $volume = '0';        // bought since the anchor
        $cost = '0';
        $fills = 0;
        $burned = null;       // the grade burned since the anchor, while one grade throughout
        $previous = null;

        foreach ($entries as $entry) {
            $data = $entry->data;
            $sincePrevious = $previous === null ? null : Decimal::subtract($data->odometerKm, $previous->data->odometerKm);
            $previous = $entry;
            $segment = null;

            if ($data->isMissedPrevious) {
                // Unknown fuel went in since the anchor: discard the open segment.
                $anchor = null;
            }

            if ($anchor === null) {
                $status = $entry->isFull() ? EconomyStatus::Baseline : EconomyStatus::Unmeasured;
            } else {
                $volume = Decimal::add($volume, $data->volume);
                $cost = Decimal::add($cost, $data->totalCost);
                $fills++;

                if (!$entry->isFull()) {
                    $status = EconomyStatus::Partial;
                    // A partial of another (or no recorded) grade mixes the segment.
                    if ($data->grade !== $burned) {
                        $burned = null;
                    }
                } else {
                    $distance = Decimal::subtract($data->odometerKm, $anchor->data->odometerKm);
                    if (Decimal::compare($distance, '0') > 0) {
                        $status = EconomyStatus::Measured;
                        $segment = new EconomySegment($distance, $volume, $cost, $fills, $data->filledAt, $burned);
                    } else {
                        $status = EconomyStatus::Invalid;
                    }
                }
            }

            if ($entry->isFull()) {
                // Every full fill (re)starts the next segment.
                $anchor = $entry;
                $volume = '0';
                $cost = '0';
                $fills = 0;
                $burned = $data->grade;
            }

            $results[] = new FillEconomy($entry, $status, $sincePrevious, $segment);
        }

        return $results;
    }

    /**
     * @param list<FillEconomy> $series one kind, non-empty
     */
    private static function summarise(EnergyKind $kind, array $series): EconomySummary
    {
        $totalVolume = '0';
        $totalCost = '0';
        $measuredDistance = '0';
        $measuredVolume = '0';
        $measuredCost = '0';
        $segments = 0;
        $last = null;
        $lowest = null;
        $highest = null;

        foreach ($series as $fill) {
            $data = $fill->entry->data;
            $totalVolume = Decimal::add($totalVolume, $data->volume);
            $totalCost = Decimal::add($totalCost, $data->totalCost);
            $lowest = $lowest === null || Decimal::compare($data->odometerKm, $lowest) < 0 ? $data->odometerKm : $lowest;
            $highest = $highest === null || Decimal::compare($data->odometerKm, $highest) > 0 ? $data->odometerKm : $highest;

            if ($fill->segment !== null) {
                $measuredDistance = Decimal::add($measuredDistance, $fill->segment->distanceKm);
                $measuredVolume = Decimal::add($measuredVolume, $fill->segment->volume);
                $measuredCost = Decimal::add($measuredCost, $fill->segment->cost);
                $segments++;
                $last = $fill->segment;
            }
        }

        return new EconomySummary(
            kind: $kind,
            fills: count($series),
            totalVolume: $totalVolume,
            totalCost: $totalCost,
            measuredDistanceKm: $measuredDistance,
            measuredVolume: $measuredVolume,
            measuredCost: $measuredCost,
            segments: $segments,
            lastSegment: $last,
            trackedDistanceKm: Decimal::subtract($highest ?? '0', $lowest ?? '0'),
        );
    }
}
