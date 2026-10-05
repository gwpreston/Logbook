<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Support\Number\Decimal;

/**
 * Business and private distance for a vehicle and period (spec.md §7.22),
 * in kilometres. Private is the mileage log's distance driven minus
 * business, never below 0: when business is more (readings too sparse),
 * private is null and $exceeds is set.
 */
final readonly class SplitFigures
{
    public function __construct(
        /** Every driver's business trips. */
        public string $businessKm,
        /** Distance driven from the mileage log; null when nothing was measurably driven. */
        public ?string $totalKm,
        public ?string $privateKm,
        public bool $exceeds,
        /** The viewer cannot see some of the trips: show the total only. */
        public bool $totalOnly = false,
    ) {
    }

    /**
     * Business as a whole percent of the distance driven, rounded half up
     * (the *Business and private* card, spec.md §7.22); null when there is
     * no split to show: total only, business over the total, or nothing
     * driven.
     */
    public function businessPercent(): ?int
    {
        if ($this->totalOnly || $this->exceeds || $this->totalKm === null || Decimal::compare($this->totalKm, '0') <= 0) {
            return null;
        }

        return (int) Decimal::divide(Decimal::multiply($this->businessKm, '100', 3), $this->totalKm, 0);
    }

    /**
     * 100 − business, so the two always add up to 100.
     */
    public function privatePercent(): ?int
    {
        $business = $this->businessPercent();

        return $business === null ? null : 100 - $business;
    }
}
