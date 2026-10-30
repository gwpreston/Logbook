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
     * @param list<array{string, int}> $priced each priced fill-up's group and place in it, oldest first
     */
    private function __construct(private array $groups, private array $priced)
    {
    }

    /**
     * @param list<FuelEntry> $entries any order
     */
    public static function of(array $entries): self
    {
        $groups = [];
        $priced = [];
        foreach (self::sorted($entries) as $entry) {
            $scaled = Outlier::scaled($entry->data->pricePerUnit);
            if ($scaled !== null ? $scaled > 0 : self::isPriced($entry)) {
                $key = self::key($entry);
                $groups[$key] ??= self::EMPTY;
                $priced[] = [$key, count($groups[$key]['entries'])];
                $groups[$key]['entries'][] = $entry;
                $groups[$key]['at'][] = $entry->data->filledAt->getTimestamp();
                $groups[$key]['scaled'][] = $scaled;
            }
        }

        return new self($groups, $priced);
    }

    /**
     * Each priced fill-up, oldest first, as its group's key and its place in that group.
     *
     * @return list<array{string, int}>
     */
    public function priced(): array
    {
        return $this->priced;
    }

    /**
     * @return array{entries: list<FuelEntry>, at: list<int>, scaled: list<?int>}
     */
    public function group(string $key): array
    {
        return $this->groups[$key] ?? self::EMPTY;
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
     * Same fuel, same grade; no grade matches no grade.
     */
    private static function key(FuelEntry $entry): string
    {
        return $entry->data->fuel->value . '|' . ($entry->data->grade->value ?? '');
    }
}
