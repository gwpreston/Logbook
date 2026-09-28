<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

/**
 * An `<optgroup>` of the fuel picker.
 */
final readonly class FuelPickerGroup
{
    /**
     * @param list<FuelPickerOption> $options
     */
    public function __construct(
        public string $labelKey,
        public array $options,
    ) {
    }
}
