<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;

/**
 * One point of a vehicle's value series: what it was bought for, valued at
 * or sold for on a calendar date, in the vehicle's currency.
 */
final readonly class ValuePoint
{
    public function __construct(
        public ValuePointKind $kind,
        /** Calendar date (midnight UTC). */
        public DateTimeImmutable $date,
        /** Canonical decimal in the vehicle's currency. */
        public string $amount,
        /** A valuation's source, if given. */
        public ?string $source = null,
        /** A valuation's id; null for the purchase and the sale. */
        public ?int $valuationId = null,
    ) {
    }
}
