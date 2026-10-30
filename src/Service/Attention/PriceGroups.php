<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Support\Number\Decimal;

/**
 * Priced fill-ups by fuel and grade (PriceOutlier), each oldest first, with
 * when each was filled and its price as an integer. Sorted and grouped
 * once: the owner's fill-ups on every vehicle are shared by each vehicle's
 * price check on one page (Phase 54.5, K149). Pure.
 */
final readonly class PriceGroups
{
    private const array EMPTY = ['entries' => [], 'at' => [], 'scaled' => []];

    /**
     * @param array<string, array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>}> $groups
     */
    private function __construct(private array $groups)
    {
    }

    /**
     * @param list<FuelEntry> $entries any order
     */
    public static function of(array $entries): self
    {
        $groups = [];
        foreach (self::sorted($entries) as $entry) {
            if (self::isPriced($entry)) {
                $key = self::key($entry);
                $groups[$key] ??= self::EMPTY;
                $groups[$key]['entries'][] = $entry;
                $groups[$key]['at'][] = $entry->data->filledAt->getTimestamp();
                $groups[$key]['scaled'][] = self::scaled($entry->data->pricePerUnit);
            }
        }

        return new self($groups);
    }

    /**
     * The fill-ups of $entry's fuel and grade (its own included, if it is one of them).
     *
     * @return array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>}
     */
    public function like(FuelEntry $entry): array
    {
        return $this->groups[self::key($entry)] ?? self::EMPTY;
    }

    /**
     * @param list<FuelEntry> $entries
     * @return list<FuelEntry> oldest first
     */
    public static function sorted(array $entries): array
    {
        usort(
            $entries,
            static fn (FuelEntry $a, FuelEntry $b): int => ($a->data->filledAt <=> $b->data->filledAt)
                ?: ($a->vehicleId <=> $b->vehicleId)
                ?: ($a->id <=> $b->id),
        );

        return $entries;
    }

    /**
     * A price of 0 (a free charge, a courtesy tank) is never counted.
     */
    public static function isPriced(FuelEntry $entry): bool
    {
        return Decimal::compare($entry->data->pricePerUnit, '0') > 0;
    }

    /**
     * A price at Outlier::SCALE + 1 places as an integer, exactly; null for
     * one with more than Outlier::SCALE places (never a stored one).
     */
    public static function scaled(string $price): ?int
    {
        $dot = strpos($price, '.');
        if (!Decimal::isCanonical($price) || ($dot !== false && strlen($price) - $dot - 1 > Outlier::SCALE)) {
            return null;
        }

        return Decimal::toScaledInt($price, Outlier::SCALE + 1);
    }

    /**
     * Same fuel, same grade; no grade matches no grade.
     */
    private static function key(FuelEntry $entry): string
    {
        return $entry->data->fuel->value . '|' . ($entry->data->grade->value ?? '');
    }
}
