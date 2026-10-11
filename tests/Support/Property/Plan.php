<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

/**
 * A generated household (Phase 45.1 property tests): its owner, a second
 * member, and each vehicle's entries as plain data, so a test can enter the
 * same household again in another order, split, or with an entry removed.
 * Amounts are canonical decimal strings in the vehicle's currency.
 */
final readonly class Plan
{
    /**
     * @param list<VehiclePlan> $vehicles
     */
    public function __construct(
        public int $seed,
        public string $today,
        public string $ownerZone,
        public string $memberZone,
        public array $vehicles,
    ) {
    }

    public function describe(): string
    {
        $parts = [];
        foreach ($this->vehicles as $vehicle) {
            $parts[] = sprintf(
                '%s %s from %s%s, %d entries%s',
                $vehicle->key,
                $vehicle->currency,
                $vehicle->purchaseDate,
                $vehicle->saleDate === null ? '' : ' sold ' . $vehicle->saleDate,
                count($vehicle->entries),
                $vehicle->memberSeesCosts ? ', member sees costs' : ', costs hidden from member',
            );
        }

        return sprintf(
            'seed %d (replay with PROPERTY_SEEDS=%d): owner in %s, member in %s; %s',
            $this->seed,
            $this->seed,
            $this->ownerZone,
            $this->memberZone,
            implode('; ', $parts),
        );
    }
}
