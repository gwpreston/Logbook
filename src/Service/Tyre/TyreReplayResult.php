<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * What a successful replay of a vehicle's tyre changes gives (spec.md
 * §7.17): each tyre's state, rolling segments and tread measurements, and
 * the change that first put it on (its fitting: `existing` means distance
 * counts "since").
 */
final readonly class TyreReplayResult
{
    /**
     * @param array<int, TyreState> $states by tyre id (tyres with lines only)
     * @param array<int, list<TyreSegment>> $segments by tyre id
     * @param array<int, int> $fittedBy tyre id → the change id that first put it on
     * @param array<int, list<TyreMeasurement>> $measurements by tyre id, in replay order
     */
    public function __construct(
        public array $states,
        public array $segments,
        public array $fittedBy,
        public array $measurements = [],
    ) {
    }

    /**
     * @return list<TyreMeasurement> oldest first
     */
    public function measurements(int $tyreId): array
    {
        return $this->measurements[$tyreId] ?? [];
    }

    public function state(int $tyreId): ?TyreState
    {
        return $this->states[$tyreId] ?? null;
    }

    /**
     * @return list<TyreSegment>
     */
    public function segments(int $tyreId): array
    {
        return $this->segments[$tyreId] ?? [];
    }
}
