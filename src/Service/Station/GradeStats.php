<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * What a user paid at one station for one grade (or one fuel with no grade
 * recorded) in one currency (spec.md §7.33). Prices are per litre or kWh,
 * canonical decimals; the average is weighted by volume. Amount figures
 * count only the fill-ups whose amounts the user may see.
 */
final readonly class GradeStats
{
    public function __construct(
        public Fuel $fuel,
        public ?FuelGrade $grade,
        public string $currency,
        public int $visits,
        /** Litres or kWh, over the fill-ups with visible amounts. */
        public string $volume,
        public string $spend,
        public ?string $averagePrice,
        public ?string $cheapestPrice,
        public ?DateTimeImmutable $cheapestOn,
        public ?string $lastPrice,
        public ?DateTimeImmutable $lastOn,
    ) {
    }

    /** The picker value of this fuel and grade (`petrol:e10_95`). */
    public function key(): string
    {
        return $this->grade === null ? $this->fuel->value : $this->fuel->value . ':' . $this->grade->value;
    }
}
