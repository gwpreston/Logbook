<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\TaxYear;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;

/**
 * A claimant's valued trips (ClaimCalculator::value()) with each tax year's
 * car distance as the threshold counted it, so the claim report and the
 * dashboard widget never disagree (spec.md §7.22, §7.23).
 */
final readonly class ClaimValuation
{
    /**
     * @param list<ValuedTrip> $trips in claim order
     * @param array<int, array{unit: DistanceUnit, used: string}> $carDistance by tax year's start year
     * @param list<MileageRateSet> $rateSets oldest first
     */
    public function __construct(
        public array $trips,
        private array $carDistance,
        private array $rateSets,
        private string $taxYearStart,
    ) {
    }

    /**
     * How far the claimant can drive before the lower rate in the tax year
     * containing $on, at the rate set in effect then. Null when that set has
     * no threshold, or no set is in effect.
     */
    public function thresholdLeft(DateTimeImmutable $on): ?ThresholdLeft
    {
        $set = ClaimCalculator::inEffect($this->rateSets, $on);
        if ($set === null || $set->data->carThreshold === null || $set->data->carRateAfter === null) {
            return null;
        }

        $year = TaxYear::containing($on, $this->taxYearStart)->startYear();
        $used = ClaimCalculator::usedIn($this->carDistance[$year] ?? null, $set->data->distanceUnit);
        $left = Decimal::subtract($set->data->carThreshold, $used);

        return new ThresholdLeft(
            unit: $set->data->distanceUnit,
            left: Decimal::compare($left, '0') > 0 ? Decimal::round($left, ClaimCalculator::DISTANCE_SCALE) : '0',
            rateAfter: $set->data->carRateAfter,
            currency: $set->data->currency,
        );
    }
}
