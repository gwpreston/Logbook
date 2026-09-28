<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\FuelChoice;

/**
 * One `<option>` of the fuel picker: a family, or a family and grade.
 */
final readonly class FuelPickerOption
{
    public function __construct(
        public FuelChoice $choice,
        public string $labelKey,
        public bool $selected = false,
    ) {
    }

    /**
     * The form value: `family` or `family:grade`.
     */
    public function value(): string
    {
        return $this->choice->value();
    }
}
