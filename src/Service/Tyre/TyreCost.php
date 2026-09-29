<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * What a tyre form's Cost / Garage fields said (spec.md §7.17): saving
 * writes a `tyres` service record with them.
 */
final readonly class TyreCost
{
    public function __construct(
        /** Canonical decimal in the vehicle's currency; 0 is valid. */
        public string $cost,
        public ?string $vendor = null,
    ) {
    }
}
