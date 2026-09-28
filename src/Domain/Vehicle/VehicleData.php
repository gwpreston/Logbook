<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

use DateTimeImmutable;
use InvalidArgumentException;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * The editable details of a vehicle, already validated and converted to
 * storage units (capacity in litres, or kWh for electric vehicles). Decimal
 * values are canonical strings; dates are calendar dates.
 */
final readonly class VehicleData
{
    public function __construct(
        public VehicleType $type,
        public string $make,
        public string $model,
        public FuelType $fuelType,
        public ?string $nickname = null,
        public ?int $year = null,
        public ?string $registration = null,
        public ?string $vin = null,
        public ?string $capacity = null,
        public ?string $currency = null,
        public ?DateTimeImmutable $purchaseDate = null,
        public ?string $purchasePrice = null,
        public ?DateTimeImmutable $saleDate = null,
        public ?string $salePrice = null,
        /** Preselected on the fill-up form when the vehicle has no graded fill of that family yet. */
        public ?FuelGrade $defaultGrade = null,
    ) {
        if ($defaultGrade !== null && $defaultGrade->family() !== FuelGrade::defaultFamilyFor($fuelType)) {
            throw new InvalidArgumentException(
                sprintf('Grade %s does not fit a %s vehicle.', $defaultGrade->value, $fuelType->value),
            );
        }
    }
}
