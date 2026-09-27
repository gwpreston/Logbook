<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * A maintenance entry as entered, validated and in storage units. Decimals
 * are canonical strings.
 */
final readonly class MaintenanceEntryData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $performedOn,
        public MaintenanceCategory $category,
        public string $title,
        /** In the vehicle's currency; 0 is valid (DIY, warranty work). */
        public string $cost = '0.000',
        /** Kilometres, when the odometer was noted. */
        public ?string $odometerKm = null,
        public ?string $vendor = null,
        public ?string $description = null,
        /** The recurring schedule this work completes, if any. */
        public ?int $scheduleId = null,
    ) {
    }
}
