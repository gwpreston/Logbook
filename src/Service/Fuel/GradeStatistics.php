<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * Figures by fuel grade (spec.md §7.3), all views over the same fill-ups and
 * segments FuelEconomy derives: grades never change the family's series.
 *
 * Pure: no I/O, so every rule is unit-tested with worked examples.
 */
final class GradeStatistics
{
    /** Grades offered first on the picker ("Used on this vehicle"). */
    public const int RECENT_LIMIT = 4;

    /**
     * One kind of energy split by grade: fills, volume and cost per grade
     * (free charges included), and the economy of the segments burned
     * entirely on it (EconomySegment::$grade).
     */
    public static function breakdown(FuelHistory $history, EnergyKind $kind): GradeBreakdown
    {
        /**
         * @var array<string, array{grade: ?FuelGrade, fills: int, volume: string, cost: string,
         *     segments: int, distance: string, burned: string}> $rows
         */
        $rows = [];
        $totalVolume = '0';
        $totalCost = '0';

        foreach ($history->ofKind($kind) as $fill) {
            $data = $fill->entry->data;
            $key = $data->grade->value ?? '';
            $rows[$key] ??= [
                'grade' => $data->grade,
                'fills' => 0,
                'volume' => '0',
                'cost' => '0',
                'segments' => 0,
                'distance' => '0',
                'burned' => '0',
            ];
            $rows[$key]['fills']++;
            $rows[$key]['volume'] = Decimal::add($rows[$key]['volume'], $data->volume);
            $rows[$key]['cost'] = Decimal::add($rows[$key]['cost'], $data->totalCost);
            $totalVolume = Decimal::add($totalVolume, $data->volume);
            $totalCost = Decimal::add($totalCost, $data->totalCost);
        }

        foreach ($history->measured($kind) as $fill) {
            $segment = $fill->segment;
            $grade = $segment?->grade;
            if ($segment === null || $grade === null || !isset($rows[$grade->value])) {
                continue;
            }
            $rows[$grade->value]['segments']++;
            $rows[$grade->value]['distance'] = Decimal::add($rows[$grade->value]['distance'], $segment->distanceKm);
            $rows[$grade->value]['burned'] = Decimal::add($rows[$grade->value]['burned'], $segment->volume);
        }

        $summaries = array_map(static fn (array $row): GradeSummary => new GradeSummary(
            grade: $row['grade'],
            fills: $row['fills'],
            volume: $row['volume'],
            cost: $row['cost'],
            kindVolume: $totalVolume,
            segments: $row['segments'],
            measuredDistanceKm: $row['distance'],
            measuredVolume: $row['burned'],
        ), array_values($rows));

        // Most bought first; "not recorded" last; ties in the enum's order.
        $order = array_flip(array_map(static fn (FuelGrade $g): string => $g->value, FuelGrade::cases()));
        usort($summaries, static function (GradeSummary $a, GradeSummary $b) use ($order): int {
            if (($a->grade === null) !== ($b->grade === null)) {
                return $a->grade === null ? 1 : -1;
            }

            return Decimal::compare($b->volume, $a->volume)
                ?: ($order[$a->grade->value ?? ''] ?? 0) <=> ($order[$b->grade->value ?? ''] ?? 0);
        });

        return new GradeBreakdown($kind, $summaries, $totalVolume, $totalCost);
    }

    /**
     * The grades used on the vehicle since $since, most fills first (then
     * most recent), at most RECENT_LIMIT.
     *
     * @param list<FuelEntry> $entries oldest first
     * @return list<FuelGrade>
     */
    public static function recentlyUsed(array $entries, DateTimeImmutable $since): array
    {
        /** @var array<string, array{grade: FuelGrade, count: int, last: int}> $used */
        $used = [];
        foreach ($entries as $position => $entry) {
            $grade = $entry->data->grade;
            if ($grade === null || $entry->data->filledAt < $since) {
                continue;
            }
            $used[$grade->value] ??= ['grade' => $grade, 'count' => 0, 'last' => 0];
            $used[$grade->value]['count']++;
            $used[$grade->value]['last'] = $position;
        }

        usort($used, static fn (array $a, array $b): int => [$b['count'], $b['last']] <=> [$a['count'], $a['last']]);

        return array_slice(array_map(static fn (array $u): FuelGrade => $u['grade'], $used), 0, self::RECENT_LIMIT);
    }

    /**
     * The grade preselected when logging $family (spec.md §7.3): that of the
     * vehicle's most recent fill-up of the same family, else the vehicle's
     * default grade when it is of that family, else none. A plug-in hybrid's
     * charge never takes its petrol grade.
     *
     * @param list<FuelEntry> $entries oldest first
     */
    public static function formDefault(array $entries, Vehicle $vehicle, Fuel $family): ?FuelGrade
    {
        foreach (array_reverse($entries) as $entry) {
            if ($entry->data->fuel === $family && $entry->data->grade !== null) {
                return $entry->data->grade;
            }
        }

        $default = $vehicle->data->defaultGrade;

        return $default !== null && $default->family() === $family ? $default : null;
    }
}
