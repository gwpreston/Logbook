<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Support\Money\Money;

/**
 * A vehicle's fuel over the horizon (spec.md §7.18): the projected distance
 * of each month × the last 12 months' fuel cost per distance. The months
 * are empty when it cannot be estimated (see status).
 */
final readonly class FuelEstimate
{
    /**
     * @param list<Money> $months one per horizon month when ready, else []
     */
    public function __construct(
        public Vehicle $vehicle,
        public string $currency,
        public FuelRateStatus $status,
        public array $months = [],
        /** Money per km when ready. */
        public ?string $perKm = null,
        /** The span of driving and spending the rate is worked out from, for the working (spec.md §8). */
        public ?ReportPeriod $window = null,
        /** The average distance a day the months are projected from, km. */
        public ?float $kmPerDay = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === FuelRateStatus::Ready;
    }

    public function total(): ?Money
    {
        if (!$this->isReady()) {
            return null;
        }

        return array_reduce(
            $this->months,
            static fn (Money $sum, Money $month): Money => $sum->add($month),
            Money::zero($this->currency),
        );
    }
}
