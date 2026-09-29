<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use Logbook\Support\Money\Money;

/**
 * One currency's 12 months (spec.md §7.18): planned, fuel and the two
 * together, per month and in all. Amounts are never converted. With any
 * item of unknown cost the totals are "at least".
 */
final readonly class ForecastTotals
{
    /**
     * @param list<ForecastMonthTotal> $months one per horizon month
     */
    public function __construct(
        public string $currency,
        public array $months,
        /** Some vehicle in this currency has a fuel estimate. */
        public bool $hasFuel,
        /** Vehicles in this currency whose fuel cannot be estimated yet. */
        public int $fuelMissing = 0,
    ) {
    }

    public function planned(): Money
    {
        return $this->sum(static fn (ForecastMonthTotal $m): Money => $m->planned);
    }

    public function fuel(): Money
    {
        return $this->sum(static fn (ForecastMonthTotal $m): Money => $m->fuel);
    }

    public function total(): Money
    {
        return $this->planned()->add($this->fuel());
    }

    public function unknown(): int
    {
        return array_sum(array_map(static fn (ForecastMonthTotal $m): int => $m->unknown, $this->months));
    }

    public function isAtLeast(): bool
    {
        return $this->unknown() > 0;
    }

    /**
     * @param callable(ForecastMonthTotal): Money $part
     */
    private function sum(callable $part): Money
    {
        $sum = Money::zero($this->currency);
        foreach ($this->months as $month) {
            $sum = $sum->add($part($month));
        }

        return $sum;
    }
}
