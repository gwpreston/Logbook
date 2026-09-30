<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

/**
 * Business mileage per vehicle for a period, with the fleet total (spec.md
 * §7.7 *Business mileage*).
 */
final readonly class BusinessMileageReport
{
    /**
     * @param list<BusinessMileageRow> $rows
     * @param list<ClaimTotals> $claim the viewer's own claim across the rows, per currency
     */
    public function __construct(
        public array $rows,
        /** Business km of the rows whose split the viewer may see. */
        public string $businessKm = '0',
        /** Distance driven of those rows, km; null when none is known. */
        public ?string $totalKm = null,
        public array $claim = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Business share of the fleet's distance, 0–100.
     */
    public function share(): ?float
    {
        if ($this->totalKm === null || (float) $this->totalKm <= 0) {
            return null;
        }

        return min(100.0, (float) $this->businessKm / (float) $this->totalKm * 100);
    }
}
