<?php

declare(strict_types=1);

namespace Logbook\Domain\Valuation;

use DateTimeImmutable;

/**
 * What a vehicle was said to be worth on a date (spec.md §6
 * VehicleValuation): a dealer's offer, an online valuation, an insurer's
 * figure. Not a cost, and never extrapolated.
 */
final readonly class VehicleValuation
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public VehicleValuationData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
