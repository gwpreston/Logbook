<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;

/**
 * A station as a provider's feed describes it (spec.md §6 ProviderStation),
 * already mapped to Logbook's grade codes. Positions are canonical
 * decimals with six places, both or neither.
 */
final readonly class FeedStation
{
    /**
     * @param list<FuelGrade> $grades
     * @param array<string, mixed>|null $openingHours as the provider gives them
     * @param list<string> $amenities
     */
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $brand = null,
        public ?string $address = null,
        public ?string $postcode = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
        public ?array $openingHours = null,
        public array $amenities = [],
        public array $grades = [],
        public bool $temporarilyClosed = false,
        public bool $permanentlyClosed = false,
    ) {
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
