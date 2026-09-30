<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * One vehicle's business mileage for a period (spec.md §7.7 *Business
 * mileage*, §7.22): the split, the viewer's claim, and cost per business
 * distance beside the claim value per business distance.
 */
final readonly class BusinessMileageRow
{
    /**
     * @param list<ClaimTotals> $claim the viewer's own claim on this vehicle, per currency
     */
    public function __construct(
        public Vehicle $vehicle,
        public SplitFigures $split,
        public array $claim,
        /** The viewer's valued business distance on this vehicle, km. */
        public string $claimedKm,
        /** Cost of ownership per km for the period (canonical), in $currency; null = "—". */
        public ?string $costPerKm,
        public string $currency,
        /** True when the cost per km is running costs alone (no depreciation known). */
        public bool $costIsRunningOnly = false,
    ) {
    }

    /**
     * Business share of the distance driven, 0–100; null without both.
     */
    public function share(): ?float
    {
        if ($this->split->totalOnly || $this->split->totalKm === null || (float) $this->split->totalKm <= 0) {
            return null;
        }

        return min(100.0, (float) $this->split->businessKm / (float) $this->split->totalKm * 100);
    }

    /**
     * The claim value per business km in the claim's currency (the first,
     * when there are several); null without a valued distance.
     *
     * @return array{perKm: string, currency: string}|null
     */
    public function claimPerKm(): ?array
    {
        $claim = $this->claim[0] ?? null;
        if ($claim === null || (float) $this->claimedKm <= 0) {
            return null;
        }

        return [
            'perKm' => Decimal::divide($claim->approvedAmount(), $this->claimedKm, 6),
            'currency' => $claim->currency,
        ];
    }
}
