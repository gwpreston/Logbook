<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

use Logbook\Support\Number\PercentDifference;

/**
 * How much more or less one grade costs per distance than the family's
 * reference grade (spec.md §7.3, *Grade verdict*). Ratios are canonical
 * decimals (6 places), the same in every unit; null when not known.
 */
final readonly class GradeVerdict
{
    public function __construct(
        public FuelGrade $grade,
        /** The family's most-used grade (by volume, last 12 months). */
        public FuelGrade $reference,
        public GradeVerdictStatus $status,
        /** Median of the time-matched price ratios (grade ÷ reference). */
        public ?string $pricePremium = null,
        /** Grade's consumption ÷ reference's, in fuel used (L/100 km). */
        public ?string $economyRatio = null,
        /** Price premium × economy ratio: the cost per distance ratio. */
        public ?string $costRatio = null,
        /** Single-grade segments of the grade. */
        public int $gradeSegments = 0,
        /** Single-grade segments of the reference grade. */
        public int $referenceSegments = 0,
        /** Fills of the grade paired with a reference fill within 30 days. */
        public int $pairs = 0,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === GradeVerdictStatus::Ok;
    }

    public function cost(): ?PercentDifference
    {
        return $this->costRatio === null ? null : PercentDifference::of($this->costRatio);
    }

    public function price(): ?PercentDifference
    {
        return $this->pricePremium === null ? null : PercentDifference::of($this->pricePremium);
    }

    public function fuelUsed(): ?PercentDifference
    {
        return $this->economyRatio === null ? null : PercentDifference::of($this->economyRatio);
    }
}
