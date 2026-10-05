<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * Everything §7.35 shows for one vehicle: its true cost since bought, over
 * the last 12 months (and the 12 before, for the change), by calendar year,
 * and what changed from each year to the next.
 */
final readonly class VehicleTrueCost
{
    /**
     * @param list<TrueCost> $years oldest first
     * @param array<int, CostChange> $changes by the later year
     */
    public function __construct(
        public Vehicle $vehicle,
        public string $currency,
        public OwnershipCost $ownership,
        public TrueCost $sinceBought,
        public ?TrueCost $lastTwelveMonths,
        public ?TrueCost $previousTwelveMonths,
        public array $years,
        public array $changes,
    ) {
    }

    public function period(TrueCostRange $range): ?TrueCost
    {
        return match ($range) {
            TrueCostRange::SinceBought => $this->sinceBought,
            TrueCostRange::TwelveMonths => $this->lastTwelveMonths,
            TrueCostRange::PreviousTwelveMonths => $this->previousTwelveMonths,
            TrueCostRange::Year => null,
        };
    }

    /**
     * Last 12 months against the 12 before, per km; null without both rates.
     */
    public function twelveMonthChange(): ?string
    {
        $now = $this->lastTwelveMonths?->perKm;
        $was = $this->previousTwelveMonths?->perKm;

        return $now === null || $was === null ? null : Decimal::subtract($now, $was);
    }

    public function changeFor(int $year): ?CostChange
    {
        return $this->changes[$year] ?? null;
    }

    /**
     * Years with enough distance to draw: the rest are in the table only.
     *
     * @return list<TrueCost>
     */
    public function chartYears(): array
    {
        return array_values(array_filter($this->years, static fn (TrueCost $year): bool => $year->isComparable()));
    }
}
