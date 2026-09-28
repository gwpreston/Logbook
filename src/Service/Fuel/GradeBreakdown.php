<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

use Logbook\Domain\Fuel\EnergyKind;
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
    ) {
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
