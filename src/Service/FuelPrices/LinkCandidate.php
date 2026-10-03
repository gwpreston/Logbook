<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\ProviderStation;

/**
 * A provider station offered under *Is this the same station?* (spec.md
 * §7.34 *Linking stations*).
 */
final readonly class LinkCandidate
{
    public function __construct(
        public ProviderStation $station,
        /** Straight-line distance from the Logbook station, when both have a position. */
        public ?float $km,
        /** Name similarity, 0–100. */
        public float $similarity,
    ) {
    }
}
