<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\FuelPrices\ProviderStation;
use Logbook\Domain\Station\Station;

/**
 * One result of *Cheapest near me* (spec.md §7.34 *Columns*).
 */
final readonly class NearRow
{
    public function __construct(
        public ProviderStation $providerStation,
        /** The Logbook station linked to it, if any. */
        public ?Station $station,
        public ListedPrice $listed,
        public bool $fresh,
        public EffectiveCost $cost,
        /** Against the nearest station selling the grade; null for that one. */
        public ?WorthIt $worthIt,
    ) {
    }

    public function isNearest(): bool
    {
        return $this->worthIt === null;
    }
}
