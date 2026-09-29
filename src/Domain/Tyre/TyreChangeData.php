<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

use DateTimeImmutable;

/**
 * The editable part of a tyre change (spec.md §6 TyreChange): its lines are
 * fixed once saved.
 */
final readonly class TyreChangeData
{
    public function __construct(
        /** Calendar date (midnight UTC; see Support\Date\LocalTime). */
        public DateTimeImmutable $doneOn,
        /** Kilometres; required for every kind but a repair. */
        public ?string $odometerKm = null,
        /** The `tyres` service record that carries the cost, if any. */
        public ?int $maintenanceEntryId = null,
        public ?string $note = null,
    ) {
    }
}
