<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * One listed price change of a tracked station (`listed_price_changes`,
 * spec.md §6 ListedPriceChange).
 */
final readonly class PriceChange
{
    public function __construct(
        public FuelGrade $grade,
        public string $price,
        public DateTimeImmutable $reportedAt,
    ) {
    }
}
