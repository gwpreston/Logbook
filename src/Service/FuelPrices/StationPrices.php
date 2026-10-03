<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\FuelPrices\ListedPrice;
use Logbook\Domain\FuelPrices\ProviderStation;

/**
 * A linked station's provider station and its listed prices by grade
 * (spec.md §7.34 *Station page*).
 */
final readonly class StationPrices
{
    /**
     * @param array<string, ListedPrice> $prices by grade code
     */
    public function __construct(
        public PriceProvider $provider,
        public ProviderStation $providerStation,
        public array $prices,
        public DateTimeImmutable $now,
    ) {
    }

    public function price(string $grade): ?ListedPrice
    {
        return $this->prices[$grade] ?? null;
    }

    /**
     * A fresh price for the grade, or none.
     */
    public function fresh(string $grade): ?ListedPrice
    {
        $listed = $this->price($grade);

        return $listed !== null && $listed->isFresh($this->now) ? $listed : null;
    }
}
