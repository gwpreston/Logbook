<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * The editable part of a recurring schedule, validated and in storage units:
 * "every intervalKm or intervalMonths, whichever comes first". At least one
 * interval is set. The baseline is when it was last done before any entry
 * was logged against it.
 */
final readonly class MaintenanceScheduleData
{
    public function __construct(
        public MaintenanceCategory $category,
        public string $title,
        /** Kilometres, canonical decimal. */
        public ?string $intervalKm = null,
        public ?int $intervalMonths = null,
        /** Calendar date. */
        public ?DateTimeImmutable $baselineDoneOn = null,
        /** Kilometres. */
        public ?string $baselineDoneKm = null,
    ) {
    }
}
