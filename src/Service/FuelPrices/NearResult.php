<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Number\Decimal;

/**
 * *Cheapest near me*'s answer (spec.md §7.34).
 */
final readonly class NearResult
{
    /**
     * @param list<NearRow> $rows in the chosen order, at most 50
     */
    public function __construct(
        public PriceProvider $provider,
        public NearOrigin $origin,
        public VehicleFuelProfile $profile,
        public FuelGrade $grade,
        public float $radiusKm,
        public NearSort $sort,
        public bool $includeOlder,
        public array $rows,
        public int $total,
        public ?NearRow $nearest,
        public ?DateTimeImmutable $lastSync,
        /** The mean listed price per unit over every fresh price in the radius (Phase 33.4); null with none. */
        public ?string $averagePrice = null,
    ) {
    }

    /**
     * The lowest listed price among the rows, the *Cheapest* badge's.
     */
    public function cheapestPrice(): ?string
    {
        $lowest = null;
        foreach ($this->rows as $row) {
            if ($lowest === null || Decimal::compare($row->listed->price, $lowest) < 0) {
                $lowest = $row->listed->price;
            }
        }

        return $lowest;
    }

    /**
     * A row's listed price against the area average (positive: dearer).
     */
    public function againstAverage(NearRow $row): ?string
    {
        return $this->averagePrice === null ? null : Decimal::subtract($row->listed->price, $this->averagePrice);
    }
}
