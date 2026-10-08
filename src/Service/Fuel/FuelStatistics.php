<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;

/**
 * Phase 16's fuel statistics for a period (spec.md §7.7): fill-ups, volume,
 * spend and average price, and economy over the tanks measured full to full
 * that ended in the period; by grade too. Ask's `fuel_stats` tool (§7.26)
 * and the API's fuel report (§7.20) both add up here.
 */
final class FuelStatistics
{
    /**
     * The fill-ups made in the period (calendar days in $zone), one grade
     * or all.
     *
     * @param list<FillEconomy> $fills
     * @return list<FillEconomy>
     */
    public static function inPeriod(
        array $fills,
        ?DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeZone $zone,
        ?FuelGrade $grade = null,
    ): array {
        return array_values(array_filter($fills, static function (FillEconomy $fill) use ($from, $to, $zone, $grade): bool {
            $day = LocalTime::dateOf($fill->entry->data->filledAt, $zone);

            return ($from === null || $day >= $from) && $day <= $to
                && ($grade === null || $fill->entry->data->grade === $grade);
        }));
    }

    /**
     * @param list<FillEconomy> $fills of one kind of energy
     * @param FuelGrade|null $grade count only segments of this grade towards economy
     */
    public static function totals(array $fills, ?FuelGrade $grade = null): FuelTotals
    {
        $volume = '0';
        $spend = '0';
        $distance = '0';
        $measured = '0';
        foreach ($fills as $fill) {
            $volume = Decimal::add($volume, $fill->entry->data->volume);
            $spend = Decimal::add($spend, $fill->entry->data->totalCost);
            $segment = $fill->status === EconomyStatus::Measured ? $fill->segment : null;
            if ($segment !== null && ($grade === null || $segment->grade === $grade)) {
                $distance = Decimal::add($distance, $segment->distanceKm);
                $measured = Decimal::add($measured, $segment->volume);
            }
        }

        return new FuelTotals(
            count($fills),
            $volume,
            $spend,
            $distance,
            $measured,
            Decimal::compare($volume, '0') > 0 ? Decimal::divide($spend, $volume, 6) : null,
        );
    }

    /**
     * @param list<FillEconomy> $fills
     * @return array<string, list<FillEconomy>> grade value ('' unrecorded) → fills
     */
    public static function byGrade(array $fills): array
    {
        $groups = [];
        foreach ($fills as $fill) {
            $groups[$fill->entry->data->grade->value ?? ''][] = $fill;
        }

        return $groups;
    }
}
