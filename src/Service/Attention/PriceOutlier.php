<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Closure;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Support\Number\Decimal;

/**
 * Fuel price outliers (spec.md §7.24 item 8): a fill-up whose price per
 * unit is far from what was paid around that time for the same fuel and
 * grade (for electricity, the grade is how it was charged, so home and
 * rapid prices are judged apart).
 *
 * Each fill-up is compared with the median price of the vehicle's other
 * fill-ups of the same fuel and grade within WINDOW_DAYS either side; with
 * fewer than MIN_NEIGHBOURS, with the owner's fill-ups of that fuel and
 * grade on any of their vehicles in the same currency. A price of 0 (a
 * free charge, a courtesy tank) is never flagged and never counted.
 *
 * Pure and exact (in integers where the prices allow, else Decimal). The
 * owner's other fill-ups are asked for only when a fill-up needs them, and
 * at most once.
 */
final class PriceOutlier
{
    public const int WINDOW_DAYS = 30;
    public const int MIN_NEIGHBOURS = 3;

    /**
     * @param list<FuelEntry> $entries the vehicle's fill-ups
     * @param Closure(): (list<FuelEntry>|PriceGroups) $owners the owner's fill-ups on every vehicle in
     *        this one's currency (this one's included), grouped already when a page shares them
     * @param int $percent flagged when more than this far above or below the median
     * @return list<PriceFinding> oldest first
     */
    public static function of(array $entries, Closure $owners, int $percent): array
    {
        $own = PriceGroups::of($entries);
        $all = null;
        $high = Decimal::divide((string) (100 + $percent), '100', 2);
        $low = Decimal::divide((string) (100 - $percent), '100', 2);

        $findings = [];
        foreach (PriceGroups::sorted($entries) as $entry) {
            if (!PriceGroups::isPriced($entry)) {
                continue;
            }
            $group = $own->like($entry);
            $near = self::neighbours($entry, $group);
            $wider = false;
            if (count($near) < self::MIN_NEIGHBOURS) {
                if ($all === null) {
                    $loaded = $owners();
                    $all = $loaded instanceof PriceGroups ? $loaded : PriceGroups::of($loaded);
                }
                $group = $all->like($entry);
                $near = self::neighbours($entry, $group);
                $wider = true;
            }
            if (count($near) < self::MIN_NEIGHBOURS) {
                continue;
            }

            $median = self::outside($entry, $group, $near, $percent)
                ?? self::outsideExactly($entry, $group, $near, $high, $low);
            if ($median === false) {
                continue;
            }
            $ratio = Outlier::ratio($entry->data->pricePerUnit, $median);
            $findings[] = new PriceFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio), $wider);
        }

        return $findings;
    }

    /**
     * The median when the fill-up is outside the band, false when it is
     * inside; null when this quick test can't say exactly (a price with
     * more places than stored, or too large for an int).
     *
     * The test in integers at Outlier::SCALE + 1 places, as exact as
     * outsideExactly() and a few times faster (Phase 54.5, K149): it is run
     * for every fill-up on the dashboard.
     *
     * @param array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>} $group
     * @param list<int> $near positions in $group
     */
    private static function outside(FuelEntry $entry, array $group, array $near, int $percent): string|false|null
    {
        $price = Outlier::scaled($entry->data->pricePerUnit);
        $prices = [];
        foreach ($near as $i) {
            $prices[] = $group['scaled'][$i];
        }
        if ($price === null || in_array(null, $prices, true)) {
            return null;
        }
        $median = Outlier::scaledMedian($prices);
        $factor = max(100, abs(100 + $percent), abs(100 - $percent));
        if (max($price, $median) > intdiv(PHP_INT_MAX, $factor)) {
            return null;
        }

        // price > median × (100 + p) / 100, or below median × (100 - p) / 100.
        $above = $price * 100 > $median * (100 + $percent);
        $below = $price * 100 < $median * (100 - $percent);

        return $above || $below ? Decimal::fromScaledInt($median, Outlier::SCALE + 1) : false;
    }

    /**
     * The same test on the prices as written, for those outside() can't take.
     *
     * @param array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>} $group
     * @param list<int> $near positions in $group
     */
    private static function outsideExactly(FuelEntry $entry, array $group, array $near, string $high, string $low): string|false
    {
        $prices = [];
        foreach ($near as $i) {
            $prices[] = $group['entries'][$i]->data->pricePerUnit;
        }
        assert($prices !== []);
        $median = Outlier::median($prices);
        $price = $entry->data->pricePerUnit;
        $above = Decimal::compare($price, Decimal::multiply($median, $high, Outlier::SCALE + 3)) > 0;
        $below = Decimal::compare($price, Decimal::multiply($median, $low, Outlier::SCALE + 3)) < 0;

        return $above || $below ? $median : false;
    }

    /**
     * The positions of the fill-ups within the window of $entry, itself left out.
     *
     * @param array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>} $group one fuel and grade, oldest
     *        first, with when each was filled and its price in integers
     * @return list<int>
     */
    private static function neighbours(FuelEntry $entry, array $group): array
    {
        $at = $entry->data->filledAt->getTimestamp();
        $window = self::WINDOW_DAYS * 86400;
        $times = $group['at'];

        // The first fill-up within the window, by bisection: a vehicle's whole history is not scanned for each of its fill-ups.
        $low = 0;
        $high = count($times);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($times[$middle] - $at < -$window) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $near = [];
        for ($i = $low, $count = count($times); $i < $count && $times[$i] - $at <= $window; $i++) {
            $other = $group['entries'][$i];
            if ($other->id === $entry->id && $other->vehicleId === $entry->vehicleId) {
                continue;
            }
            $near[] = $i;
        }

        return $near;
    }
}
