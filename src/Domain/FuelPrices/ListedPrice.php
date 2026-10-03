<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * A provider station's current listed price for one grade (spec.md §6
 * ProviderPrice), and whether it is fresh (§7.34 *Freshness*).
 */
final readonly class ListedPrice
{
    /** Older than this is "may be out of date" and left out of rankings. */
    public const int FRESH_HOURS = 48;

    public function __construct(
        public FuelGrade $grade,
        public string $price,
        public DateTimeImmutable $reportedAt,
        public ?DateTimeImmutable $syncedAt = null,
    ) {
    }

    public function isFresh(DateTimeImmutable $now): bool
    {
        return self::freshAt($this->reportedAt, $now);
    }

    /**
     * Reported no more than 48 hours before $at (and not after it).
     */
    public static function freshAt(DateTimeImmutable $reportedAt, DateTimeImmutable $at): bool
    {
        $age = $at->getTimestamp() - $reportedAt->getTimestamp();

        return $age >= -300 && $age <= self::FRESH_HOURS * 3600;
    }
}
