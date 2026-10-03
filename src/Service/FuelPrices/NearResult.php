<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

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
    ) {
    }
}
