<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

/**
 * One row of Fuelio's `Costs`, values as written.
 */
final readonly class FuelioCost
{
    public function __construct(
        public int $line,
        public string $guid,
        public string $uniqueId,
        public string $title,
        public string $date,
        public string $odometer,
        public int $typeId,
        public string $notes,
        public string $cost,
        public bool $isTemplate,
        public bool $isIncome,
        /** Distance between repeats, in the export's distance unit; 0 when none. */
        public string $repeatOdometer,
        public int $repeatMonths,
    ) {
    }

    public function repeats(): bool
    {
        return $this->repeatMonths > 0 || (is_numeric($this->repeatOdometer) && (float) $this->repeatOdometer > 0.0);
    }
}
