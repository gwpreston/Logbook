<?php

declare(strict_types=1);

namespace Logbook\Domain\Valuation;

use DateTimeImmutable;

/**
 * A valuation as entered and validated. The amount is a canonical decimal in
 * the vehicle's currency.
 */
final readonly class VehicleValuationData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $valuedOn,
        /** 0 is valid (a write-off or scrap value). */
        public string $amount = '0.000',
        /** Who said so: "Part-exchange offer, Arnold Clark". */
        public ?string $source = null,
        public ?string $notes = null,
    ) {
    }
}
