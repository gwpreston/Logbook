<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

use Logbook\Support\Number\Decimal;

/**
 * The `true_cost` dashboard widget (spec.md §7.35): the vehicles in view,
 * grouped by currency (the one with most vehicles first) and ranked by
 * cost per distance, highest first; those without a figure last.
 */
final readonly class TrueCostWidget
{
    /**
     * @param array<string, list<VehicleTrueCost>> $byCurrency
     */
    private function __construct(
        public TrueCostRange $range,
        public array $byCurrency,
    ) {
    }

    /**
     * @param list<VehicleTrueCost> $vehicles
     */
    public static function of(array $vehicles, TrueCostRange $range): self
    {
        $byCurrency = [];
        foreach ($vehicles as $vehicle) {
            $byCurrency[$vehicle->currency][] = $vehicle;
        }
        uksort($byCurrency, static fn (string $a, string $b): int
            => (count($byCurrency[$b]) <=> count($byCurrency[$a])) ?: strcmp($a, $b));

        foreach ($byCurrency as $currency => $rows) {
            usort($rows, static function (VehicleTrueCost $a, VehicleTrueCost $b) use ($range): int {
                $x = $a->period($range)?->perKm;
                $y = $b->period($range)?->perKm;
                if ($x === null || $y === null) {
                    return ($x === null) <=> ($y === null);
                }

                return Decimal::compare($y, $x);
            });
            $byCurrency[$currency] = $rows;
        }

        return new self($range, $byCurrency);
    }

    /**
     * Nothing to rank: no vehicle in view, or none with a figure (all "No costs
     * logged" or "Not enough mileage logged"; spec.md §7.8, #566, K157).
     */
    public function isEmpty(): bool
    {
        foreach ($this->byCurrency as $rows) {
            foreach ($rows as $vehicle) {
                if ($vehicle->period($this->range)?->perKm !== null) {
                    return false;
                }
            }
        }

        return true;
    }
}
