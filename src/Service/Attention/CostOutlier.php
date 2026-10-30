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
 * Pure and exact (in integers where the costs allow, else Decimal).
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
        // The same costs as integers (Outlier::scaled()), null for one that can't be.
        $scaled = [];
        $findings = [];
        foreach ($entries as $entry) {
            $cost = $entry->data->cost;
            if (Decimal::compare($cost, '0') <= 0) {
                continue;
            }
            $category = $entry->data->category->value;
            $before = $earlier[$category] ?? [];
            $beforeScaled = $scaled[$category] ?? [];
            $earlier[$category][] = $cost;
            $scaled[$category][] = Outlier::scaled($cost);
            if (count($before) < self::MIN_EARLIER || $entry->data->performedOn->format('Y-m-d') < $sinceDay) {
                continue;
            }

            $median = self::outside($cost, $beforeScaled, $multiple, $floor)
                ?? self::outsideExactly($cost, $before, $multiple, $floor);
            if ($median !== false) {
                $ratio = Outlier::ratio($cost, $median);
                $findings[] = new CostFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio));
            }
        }

        return $findings;
    }

    /**
     * The median when the cost is over both limits, false when it isn't;
     * null when this test in integers can't say exactly (as PriceOutlier's,
     * Phase 54.5, K149).
     *
     * @param list<?int> $before the earlier costs, Outlier::scaled()
     */
    private static function outside(string $cost, array $before, int $multiple, int $floor): string|false|null
    {
        $value = Outlier::scaled($cost);
        if ($value === null || in_array(null, $before, true)) {
            return null;
        }
        /** @var list<int> $before */
        $median = Outlier::scaledMedian($before);
        $unit = 10 ** (Outlier::SCALE + 1);
        if ($median > intdiv(PHP_INT_MAX, max(abs($multiple), 1)) || abs($floor) > intdiv(PHP_INT_MAX, $unit)) {
            return null;
        }

        $over = $value > $median * $multiple;
        $enough = $value - $median >= $floor * $unit;

        return $over && $enough ? Decimal::fromScaledInt($median, Outlier::SCALE + 1) : false;
    }

    /**
     * The same test on the costs as written.
     *
     * @param list<string> $before
     */
    private static function outsideExactly(string $cost, array $before, int $multiple, int $floor): string|false
    {
        assert($before !== []);
        $median = Outlier::median($before);
        $over = Decimal::compare($cost, Decimal::multiply($median, (string) $multiple, Outlier::SCALE + 1)) > 0;
        $enough = Decimal::compare(Decimal::subtract($cost, $median), (string) $floor) >= 0;

        return $over && $enough ? $median : false;
    }
}
