<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use Logbook\Domain\Attention\AttentionSeverity;

/**
 * The *Needs attention* items of some vehicles for one user (spec.md
 * §7.24), in list order: *Now* first, then *Check*.
 */
final readonly class AttentionReport
{
    /**
     * @param list<AttentionItem> $items in list order
     */
    public function __construct(public array $items = [])
    {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return list<AttentionItem>
     */
    public function forVehicle(int $vehicleId): array
    {
        return array_values(array_filter($this->items, static fn (AttentionItem $i): bool => $i->vehicle->id === $vehicleId));
    }

    public function count(int $vehicleId): int
    {
        return count($this->forVehicle($vehicleId));
    }

    /**
     * Item counts by vehicle id (vehicles without items are left out).
     *
     * @return array<int, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $counts[$item->vehicle->id] = ($counts[$item->vehicle->id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The *Check* items (the monthly digest's section).
     *
     * @return list<AttentionItem>
     */
    public function checks(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (AttentionItem $i): bool => $i->severity() === AttentionSeverity::Check,
        ));
    }

    /**
     * @return list<AttentionItem>
     */
    public function first(int $count): array
    {
        return array_slice($this->items, 0, $count);
    }
}
