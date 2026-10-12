<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

/**
 * One generated vehicle: what was paid for it, the reading on the day it was
 * bought, whether it was sold, its entries and any finance agreement.
 */
final readonly class VehiclePlan
{
    /**
     * @param list<Entry> $entries
     */
    public function __construct(
        public string $key,
        public string $currency,
        public string $purchaseDate,
        public string $purchasePrice,
        public string $startKm,
        public ?string $saleDate,
        public ?string $salePrice,
        public bool $memberSeesCosts,
        public array $entries,
        public ?FinancePlan $finance = null,
        /** False: no reading on the purchase day, so the log starts at the first fill-up (#376). */
        public bool $loggedFromPurchase = true,
    ) {
    }

    /**
     * The same vehicle with other entries.
     *
     * @param list<Entry> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self(
            $this->key,
            $this->currency,
            $this->purchaseDate,
            $this->purchasePrice,
            $this->startKm,
            $this->saleDate,
            $this->salePrice,
            $this->memberSeesCosts,
            $entries,
            $this->finance,
            $this->loggedFromPurchase,
        );
    }
}
