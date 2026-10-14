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
    /** *Next 3 months*: this month (with the overdue items) and the two after (Phase 44). */
    public const int SOON_MONTHS = 3;

    /**
     * @param list<ForecastMonthTotal> $months one per horizon month
     * @param list<FuelEstimate> $estimates the ready ones
     */
    public function __construct(
        public string $currency,
        public array $months,
        /** Some vehicle in this currency has a fuel estimate. */
        public bool $hasFuel,
        /** Vehicles in this currency whose fuel cannot be estimated yet. */
        public int $fuelMissing = 0,
        /** The fuel estimates the months' fuel adds up, for the working (spec.md §8). */
        public array $estimates = [],
    ) {
    }

    /**
     * The same figures over the first few months only, as *Next 3 months*
     * (spec.md §7.18): calendar months like the horizon, so they are the
     * sum of the month rows the page shows.
     */
    public function firstMonths(int $count): self
    {
        return new self(
            $this->currency,
            array_slice($this->months, 0, max(0, $count)),
            $this->hasFuel,
            $this->fuelMissing,
            $this->estimates,
        );
    }

    public function soon(): self
    {
        return $this->firstMonths(self::SOON_MONTHS);
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
