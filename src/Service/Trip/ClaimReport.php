<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * A claimant's claim report (spec.md §7.23): their business trips in the
 * period, oldest first, valued; totals per currency; the trips with no rate
 * set in effect; and the rate sets used.
 */
final readonly class ClaimReport
{
    /**
     * @param list<ValuedTrip> $rows oldest first
     * @param list<ClaimTotals> $totals
     * @param list<MileageRateSet> $rateSets used by the rows, oldest first
     * @param array<int, Vehicle> $vehicles the claimant's visible vehicles, by id
     * @param list<Vehicle> $included the vehicles the rows are from
     */
    public function __construct(
        public ClaimFilter $filter,
        public array $rows,
        public array $totals,
        public int $unvalued,
        public array $rateSets,
        public array $vehicles,
        public array $included,
        public TripSettings $settings,
        public ?ThresholdLeft $thresholdLeft = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function vehicle(int $id): ?Vehicle
    {
        return $this->vehicles[$id] ?? null;
    }

    public function hasEmployerRates(): bool
    {
        foreach ($this->totals as $totals) {
            if ($totals->employerAmount !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Business distance in km over the rows, valued or not.
     */
    public function distanceKm(): string
    {
        $sum = '0';
        foreach ($this->rows as $row) {
            $sum = Decimal::add($sum, $row->trip->data->distanceKm);
        }

        return $sum;
    }
}
