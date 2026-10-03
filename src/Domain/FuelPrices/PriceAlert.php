<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * "Alert me below" on a favourite linked station (spec.md §6 PriceAlert,
 * §7.34 *Price alerts*). Armed while `triggeredAt` is null.
 */
final readonly class PriceAlert
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $stationId,
        public FuelGrade $grade,
        public string $below,
        public ?DateTimeImmutable $triggeredAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isArmed(): bool
    {
        return $this->triggeredAt === null;
    }
}
