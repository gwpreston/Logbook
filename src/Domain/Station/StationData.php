<?php

declare(strict_types=1);

namespace Logbook\Domain\Station;

use InvalidArgumentException;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * A station as entered and validated (spec.md §6 Station). Coordinates are
 * canonical decimal strings with 6 places, both or neither.
 */
final readonly class StationData
{
    /**
     * @param list<FuelGrade> $grades the grades sold, in the order given
     */
    public function __construct(
        public string $name,
        public ?string $brand = null,
        public ?string $address = null,
        public ?string $postcode = null,
        /** ISO 3166-1 alpha-2, upper case. */
        public ?string $country = null,
        public ?string $latitude = null,
        public ?string $longitude = null,
        public array $grades = [],
        public ?string $openingHours = null,
        public ?string $notes = null,
    ) {
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidArgumentException('A station position needs both latitude and longitude.');
        }
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function sells(FuelGrade $grade): bool
    {
        return in_array($grade, $this->grades, true);
    }
}
