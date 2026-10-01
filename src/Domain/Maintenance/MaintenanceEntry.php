<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

use DateTimeImmutable;

/**
 * One piece of work in a vehicle's service history (spec.md §6
 * MaintenanceEntry). Its odometer, when given, is also a reading in the
 * mileage series.
 */
final readonly class MaintenanceEntry
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public MaintenanceEntryData $data,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /** Who added it (Phase 19); null = the vehicle's owner, or a former user. */
        public ?int $createdBy = null,
        /** The incident it is part of (Phase 27.1, spec.md §7.29). */
        public ?int $incidentId = null,
    ) {
    }
}
