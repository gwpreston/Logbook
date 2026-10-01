<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use DateTimeImmutable;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Support\Number\Decimal;

/**
 * Maintenance cost outliers (spec.md §7.24 item 9): a record that cost far
 * more than the vehicle's earlier records in the same category.
 *
 * Each record dated on or after $since is compared with the median of the
 * category's earlier records (by date, then the order added) with a cost
 * above 0; with fewer than MIN_EARLIER it is not judged. Flagged when more
 * than $multiple times the median **and** at least $floor above it, so a
 * cheap category is never flagged over a few pounds. A record's cost is
 * always in its vehicle's currency, so nothing is converted.
 *
 * Pure and exact (Decimal throughout).
 */
final class CostOutlier
{
    public const int MIN_EARLIER = 3;

    /**
     * @param list<MaintenanceEntry> $entries the vehicle's records
     * @param DateTimeImmutable $since the first local date raised (12 months ago)
     * @param int $floor major currency units
     * @return list<CostFinding> oldest first
     */
    public static function of(array $entries, DateTimeImmutable $since, int $multiple, int $floor): array
    {
        usort(
            $entries,
            static fn (MaintenanceEntry $a, MaintenanceEntry $b): int => ($a->data->performedOn <=> $b->data->performedOn)
                ?: ($a->id <=> $b->id),
        );
        $sinceDay = $since->format('Y-m-d');

        /** @var array<string, list<string>> $earlier costs above 0 so far, by category */
        $earlier = [];
        $findings = [];
        foreach ($entries as $entry) {
            $cost = $entry->data->cost;
            if (Decimal::compare($cost, '0') <= 0) {
                continue;
            }
            $category = $entry->data->category->value;
            $before = $earlier[$category] ?? [];
            $earlier[$category][] = $cost;
            if (count($before) < self::MIN_EARLIER || $entry->data->performedOn->format('Y-m-d') < $sinceDay) {
                continue;
            }

            $median = Outlier::median($before);
            $over = Decimal::compare($cost, Decimal::multiply($median, (string) $multiple, Outlier::SCALE + 1)) > 0;
            $enough = Decimal::compare(Decimal::subtract($cost, $median), (string) $floor) >= 0;
            if ($over && $enough) {
                $ratio = Outlier::ratio($cost, $median);
                $findings[] = new CostFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio));
            }
        }

        return $findings;
    }
}
