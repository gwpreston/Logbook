<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\Station\Station;

/**
 * "Compared with your usual Tesco Antrim (£1.400): saved £2.00 on fuel,
 * about £0.50 for the extra 4 mi, £1.50 better off" (spec.md §7.34 *After
 * a fill-up*). A derived figure, never stored.
 */
final readonly class FillUpComparison
{
    public function __construct(
        public Station $usual,
        public ListedPrice $usualListed,
        public ListedPrice $ownListed,
        /** (usual listed − price paid) × volume. */
        public string $fuelSaving,
        /** The extra distance by road (km; negative when this station is nearer home), or none without Home. */
        public ?float $extraRoadKm,
        /** Its fuel at the price paid, or none. */
        public ?string $extraCost,
        /** Fuel saving − extra cost, or none without the distance. */
        public ?string $total,
        public string $currency,
    ) {
    }

    public function hasDistance(): bool
    {
        return $this->total !== null;
    }

    /** The figure that counts: the total, else the fuel saving alone. */
    public function result(): string
    {
        return $this->total ?? $this->fuelSaving;
    }
}
