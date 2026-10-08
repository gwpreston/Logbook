<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Support\Number\Decimal;

/**
 * A vehicle's service history with its totals.
 */
final readonly class MaintenanceHistory
{
    /**
     * @param list<MaintenanceEntry> $entries oldest first
     */
    public function __construct(public array $entries)
    {
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return list<MaintenanceEntry> newest first, optionally one category only
     */
    public function newestFirst(?MaintenanceCategory $category = null): array
    {
        $entries = array_reverse($this->entries);

        return $category === null
            ? $entries
            : array_values(array_filter($entries, static fn (MaintenanceEntry $e): bool => $e->data->category === $category));
    }

    /**
     * Newest first, one category or all, and only records whose title,
     * vendor, description or category code hold every word of `$text`
     * (any case). The maintenance list's `?q=` (spec.md §7.20) and Ask's
     * `maintenance` tool (§7.26) both search this way.
     *
     * @return list<MaintenanceEntry>
     */
    public function search(?MaintenanceCategory $category = null, ?string $text = null): array
    {
        $words = $text === null ? [] : (preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($words === []) {
            return $this->newestFirst($category);
        }

        return array_values(array_filter(
            $this->newestFirst($category),
            static function (MaintenanceEntry $entry) use ($words): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $entry->data->title,
                    $entry->data->vendor,
                    $entry->data->description,
                    $entry->data->category->value,
                ])));
                foreach ($words as $word) {
                    if (!str_contains($haystack, $word)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    public function latest(): ?MaintenanceEntry
    {
        return $this->entries === [] ? null : $this->entries[array_key_last($this->entries)];
    }

    /**
     * Total spent, canonical decimal (all entries are in the vehicle's currency).
     */
    public function totalCost(): string
    {
        return array_reduce(
            $this->entries,
            static fn (string $sum, MaintenanceEntry $e): string => Decimal::add($sum, $e->data->cost),
            '0.000',
        );
    }

    /**
     * Categories that have entries, in the enum's order (for filter chips).
     *
     * @return list<MaintenanceCategory>
     */
    public function categories(): array
    {
        $used = [];
        foreach ($this->entries as $entry) {
            $used[$entry->data->category->value] = true;
        }

        return array_values(array_filter(
            MaintenanceCategory::cases(),
            static fn (MaintenanceCategory $c): bool => isset($used[$c->value]),
        ));
    }
}
