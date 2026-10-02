<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use DateTimeImmutable;
use Logbook\Support\Number\Decimal;

/**
 * A user's figures at one station (spec.md §7.33): visits and last visit
 * over every fill-up they can see there, then per grade and currency what
 * they paid, and the price history (oldest first).
 */
final readonly class StationSummary
{
    /**
     * @param list<GradeStats> $grades most volume first
     * @param list<PricePoint> $history oldest first
     */
    public function __construct(
        public int $stationId,
        public int $visits,
        public ?DateTimeImmutable $lastVisit,
        public array $grades,
        public array $history,
    ) {
    }

    /**
     * The grade the user buys most here (by volume, then visits).
     */
    public function mainGrade(): ?GradeStats
    {
        return $this->grades[0] ?? null;
    }

    /**
     * The figures for one fuel and grade (`petrol:e10_95`), in one currency.
     */
    public function grade(string $key, ?string $currency = null): ?GradeStats
    {
        foreach ($this->grades as $stats) {
            if ($stats->key() === $key && ($currency === null || $stats->currency === $currency)) {
                return $stats;
            }
        }

        return null;
    }

    /**
     * Spend per currency, over the fill-ups with visible amounts.
     *
     * @return array<string, string>
     */
    public function spend(): array
    {
        $spend = [];
        foreach ($this->grades as $stats) {
            $spend[$stats->currency] = isset($spend[$stats->currency])
                ? Decimal::add($spend[$stats->currency], $stats->spend)
                : $stats->spend;
        }

        return $spend;
    }
}
