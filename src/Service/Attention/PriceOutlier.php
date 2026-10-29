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
 * Pure and exact (Decimal throughout). The owner's other fill-ups are
 * asked for only when a fill-up needs them, and at most once.
 */
final class PriceOutlier
{
    public const int WINDOW_DAYS = 30;
    public const int MIN_NEIGHBOURS = 3;

    private const array EMPTY = ['entries' => [], 'at' => []];

    /**
     * @param list<FuelEntry> $entries the vehicle's fill-ups
     * @param Closure(): list<FuelEntry> $owners the owner's fill-ups on every vehicle in this one's
     *        currency (this one's included)
     * @param int $percent flagged when more than this far above or below the median
     * @return list<PriceFinding> oldest first
     */
    public static function of(array $entries, Closure $owners, int $percent): array
    {
        $own = self::groups($entries);
        $all = null;
        $high = Decimal::divide((string) (100 + $percent), '100', 2);
        $low = Decimal::divide((string) (100 - $percent), '100', 2);

        $findings = [];
        foreach (self::sorted($entries) as $entry) {
            if (!self::isPriced($entry)) {
                continue;
            }
            $key = self::key($entry);
            $prices = self::neighbours($entry, $own[$key] ?? self::EMPTY);
            $wider = false;
            if (count($prices) < self::MIN_NEIGHBOURS) {
                $all ??= self::groups($owners());
                $prices = self::neighbours($entry, $all[$key] ?? self::EMPTY);
                $wider = true;
            }
            if (count($prices) < self::MIN_NEIGHBOURS) {
                continue;
            }

            $median = Outlier::median($prices);
            $price = $entry->data->pricePerUnit;
            $above = Decimal::compare($price, Decimal::multiply($median, $high, Outlier::SCALE + 3)) > 0;
            $below = Decimal::compare($price, Decimal::multiply($median, $low, Outlier::SCALE + 3)) < 0;
            if (!$above && !$below) {
                continue;
            }
            $ratio = Outlier::ratio($price, $median);
            $findings[] = new PriceFinding($entry, $median, $ratio, Outlier::isDigitSlip($ratio), $wider);
        }

        return $findings;
    }

    /**
     * The prices of the fill-ups within the window of $entry, itself left out.
     *
     * @param array{entries: list<FuelEntry>, at: list<int>} $group one fuel and grade, oldest first, with when each was filled
     * @return list<string>
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

        $prices = [];
        for ($i = $low, $count = count($times); $i < $count && $times[$i] - $at <= $window; $i++) {
            $other = $group['entries'][$i];
            if ($other->id === $entry->id && $other->vehicleId === $entry->vehicleId) {
                continue;
            }
            $prices[] = $other->data->pricePerUnit;
        }

        return $prices;
    }

    /**
     * Priced fill-ups by fuel and grade, each oldest first, with when each was filled.
     *
     * @param list<FuelEntry> $entries
     * @return array<string, array{entries: list<FuelEntry>, at: list<int>}>
     */
    private static function groups(array $entries): array
    {
        $groups = [];
        foreach (self::sorted($entries) as $entry) {
            if (self::isPriced($entry)) {
                $key = self::key($entry);
                $groups[$key] ??= self::EMPTY;
                $groups[$key]['entries'][] = $entry;
                $groups[$key]['at'][] = $entry->data->filledAt->getTimestamp();
            }
        }

        return $groups;
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
