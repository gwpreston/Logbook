<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;

/**
 * Every economy check of one vehicle, by the id of the closing fill-up.
 */
final readonly class EconomyChecks
{
    /**
     * @param array<int, SegmentCheck> $checks keyed by closing fill-up id, oldest first
     */
    public function __construct(public array $checks = [])
    {
    }

    public function for(int $entryId): ?SegmentCheck
    {
        return $this->checks[$entryId] ?? null;
    }

    public function isFlagged(int $entryId): bool
    {
        return $this->for($entryId)?->isFlagged() ?? false;
    }

    /**
     * @return list<SegmentCheck>
     */
    public function flagged(): array
    {
        return array_values(array_filter($this->checks, static fn (SegmentCheck $c): bool => $c->isFlagged()));
    }

    /**
     * Flagged segments, of one kind of energy or of both.
     */
    public function flaggedCount(?EnergyKind $kind = null): int
    {
        return count(array_filter(
            $this->flagged(),
            static fn (SegmentCheck $c): bool => $kind === null || $c->entry()->data->fuel->kind() === $kind,
        ));
    }

    /**
     * How many of these fill-ups are flagged (the CSV import result).
     *
     * @param list<int> $entryIds
     */
    public function flaggedAmong(array $entryIds): int
    {
        return count(array_filter($entryIds, $this->isFlagged(...)));
    }
}
