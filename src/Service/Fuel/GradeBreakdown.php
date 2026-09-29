<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Number\Decimal;

/**
 * One kind of energy on one vehicle, split by grade (the Fuel tab's *By
 * grade* card): the grades used, most bought first, then the fills with no
 * grade recorded.
 */
final readonly class GradeBreakdown
{
    /**
     * @param list<GradeSummary> $rows graded rows by volume (largest first), then "not recorded" if any
     */
    public function __construct(
        public EnergyKind $kind,
        public array $rows,
        public string $totalVolume,
        public string $totalCost,
        /**
         * The kind's average consumption in litres (kWh) per km, 8 places:
         * the family figure over every measured segment; null without one.
         */
        public ?string $averageVolumePerKm = null,
    ) {
    }

    public function row(FuelGrade $grade): ?GradeSummary
    {
        foreach ($this->rows as $row) {
            if ($row->grade === $grade) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Cost per km of one charging type (spec.md §7.3): its cost per kWh ×
     * the vehicle's average kWh per km. The charging method does not change
     * consumption at the wheel, and single-type segments are rare. Null
     * without a price or an average.
     */
    public function costPerKm(GradeSummary $row): ?string
    {
        $price = $row->averagePricePerUnit();

        return $price === null || $this->averageVolumePerKm === null
            ? null
            : Decimal::multiply($price, $this->averageVolumePerKm, 8);
    }

    /**
     * Whether any fill of this kind has a grade (the card is hidden otherwise).
     */
    public function hasGrades(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->grade !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Money per litre (kWh) over everything bought: for electricity, the
     * blended cost per kWh.
     */
    public function blendedPricePerUnit(): ?string
    {
        return Decimal::compare($this->totalVolume, '0') > 0 ? Decimal::divide($this->totalCost, $this->totalVolume, 6) : null;
    }
}
