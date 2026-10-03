<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * One listed price from a feed (spec.md §6 ProviderPrice): per litre in the
 * provider's currency, a canonical decimal with three places, and when the
 * station reported it (UTC).
 */
final readonly class FeedPrice
{
    public function __construct(
        public string $ref,
        public FuelGrade $grade,
        public string $price,
        public DateTimeImmutable $reportedAt,
    ) {
    }
}
