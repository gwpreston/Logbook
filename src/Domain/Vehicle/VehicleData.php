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
        /** Trim / version, e.g. "1.5 EcoBoost ST-Line X". */
        public ?string $variant = null,
        /** Calendar date of first registration (not the model year, not the purchase date). */
        public ?DateTimeImmutable $firstRegisteredOn = null,
        /**
         * Calendar date the first MOT (or the local equivalent) is due; used
         * only while the vehicle has no inspection document (FirstInspection).
         */
        public ?DateTimeImmutable $firstInspectionDueOn = null,
        /** Who it was bought from (Phase 33.3): free text, up to 100. */
        public ?string $purchaseSeller = null,
    ) {
        if ($defaultGrade !== null && $defaultGrade->family() !== FuelGrade::defaultFamilyFor($fuelType)) {
            throw new InvalidArgumentException(
                sprintf('Grade %s does not fit a %s vehicle.', $defaultGrade->value, $fuelType->value),
            );
        }
    }

    /**
     * The same details with another first MOT date.
     */
    public function withFirstInspectionDueOn(?DateTimeImmutable $on): self
    {
        return new self(
            type: $this->type,
            make: $this->make,
            model: $this->model,
            fuelType: $this->fuelType,
            nickname: $this->nickname,
            year: $this->year,
            registration: $this->registration,
            vin: $this->vin,
            capacity: $this->capacity,
            currency: $this->currency,
            purchaseDate: $this->purchaseDate,
            purchasePrice: $this->purchasePrice,
            saleDate: $this->saleDate,
            salePrice: $this->salePrice,
            defaultGrade: $this->defaultGrade,
            variant: $this->variant,
            firstRegisteredOn: $this->firstRegisteredOn,
            firstInspectionDueOn: $on,
            purchaseSeller: $this->purchaseSeller,
        );
    }
}
