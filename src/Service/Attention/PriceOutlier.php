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

    private const array EMPTY = ['entries' => [], 'at' => [], 'scaled' => []];

    /**
     * @param list<FuelEntry> $entries the vehicle's fill-ups
     * @param Closure(): list<FuelEntry> $owners the owner's fill-ups on every vehicle in this one's
     *        currency (this one's included)
     * @param int $percent flagged when more than this far above or below the median
     * @return list<PriceFinding> oldest first
     */
    public static function of(array $entries, Closure $owners, int $percent): array
    {
        $sorted = self::sorted($entries);
        $own = self::groups($sorted);
        $all = null;
        $high = Decimal::divide((string) (100 + $percent), '100', 2);
        $low = Decimal::divide((string) (100 - $percent), '100', 2);

        $findings = [];
        foreach ($sorted as $entry) {
            if (!self::isPriced($entry)) {
                continue;
            }
            $key = self::key($entry);
            $group = $own[$key] ?? self::EMPTY;
            $near = self::neighbours($entry, $group);
            $wider = false;
            if (count($near) < self::MIN_NEIGHBOURS) {
                $all ??= self::groups(self::sorted($owners()));
                $group = $all[$key] ?? self::EMPTY;
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
     * for every fill-up on the dashboard. Prices of up to six places are
     * multiples of ten there, so the mean of two middle ones is exact, as
     * Outlier::median() keeps it.
     *
     * @param array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>} $group
     * @param list<int> $near positions in $group
     */
    private static function outside(FuelEntry $entry, array $group, array $near, int $percent): string|false|null
    {
        $price = self::scaled($entry->data->pricePerUnit);
        $prices = [];
        foreach ($near as $i) {
            $prices[] = $group['scaled'][$i];
        }
        if ($price === null || in_array(null, $prices, true)) {
            return null;
        }
        sort($prices);
        $count = count($prices);
        $middle = intdiv($count, 2);
        $twice = $count % 2 === 1 ? 2 * $prices[$middle] : $prices[$middle - 1] + $prices[$middle];
        $factor = max(100, abs(100 + $percent), abs(100 - $percent));
        if ($twice % 2 !== 0 || max($price, $twice) > intdiv(PHP_INT_MAX, 2 * $factor)) {
            return null;
        }
        $median = intdiv($twice, 2);

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

    /**
     * Priced fill-ups by fuel and grade, each oldest first, with when each
     * was filled and its price in integers (null when it has more places
     * than stored).
     *
     * @param list<FuelEntry> $sorted oldest first (sorted())
     * @return array<string, array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>}>
     */
    private static function groups(array $sorted): array
    {
        $groups = [];
        foreach ($sorted as $entry) {
            if (self::isPriced($entry)) {
                $key = self::key($entry);
                $groups[$key] ??= self::EMPTY;
                $groups[$key]['entries'][] = $entry;
                $groups[$key]['at'][] = $entry->data->filledAt->getTimestamp();
                $groups[$key]['scaled'][] = self::scaled($entry->data->pricePerUnit);
            }
        }

        return $groups;
    }

    /**
     * A price at Outlier::SCALE + 1 places as an integer, exactly; null for
     * one with more than Outlier::SCALE places (never a stored one).
     */
    private static function scaled(string $price): ?int
    {
        $dot = strpos($price, '.');
        if (!Decimal::isCanonical($price) || ($dot !== false && strlen($price) - $dot - 1 > Outlier::SCALE)) {
            return null;
        }

        return Decimal::toScaledInt($price, Outlier::SCALE + 1);
    }

    /**
     * @param list<FuelEntry> $entries
     * @return list<FuelEntry>
     */
    private static function sorted(array $entries): array
    {
        usort(
            $entries,
            static fn (FuelEntry $a, FuelEntry $b): int => ($a->data->filledAt <=> $b->data->filledAt)
                ?: ($a->vehicleId <=> $b->vehicleId)
                ?: ($a->id <=> $b->id),
        );

        return $entries;
    }

    private static function isPriced(FuelEntry $entry): bool
    {
        return Decimal::compare($entry->data->pricePerUnit, '0') > 0;
    }

    /**
     * Same fuel, same grade; no grade matches no grade.
     */
    private static function key(FuelEntry $entry): string
    {
        return $entry->data->fuel->value . '|' . ($entry->data->grade->value ?? '');
    }
}
