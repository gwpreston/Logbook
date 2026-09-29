<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use DateTimeImmutable;

/**
 * One tread depth taken at a tyre change (spec.md §7.17), with the tyre's
 * own distance at that change: the wear estimate's x-axis, so time in
 * storage or as the spare adds nothing.
 */
final readonly class TyreMeasurement
{
    public function __construct(
        public int $changeId,
        /** The change's calendar date. */
        public DateTimeImmutable $doneOn,
        /** Millimetres, canonical (3 places). */
        public string $treadMm,
        /** The tyre's rolling distance up to the change's odometer, km (3 places). */
        public string $distanceKm,
    ) {
    }
}
