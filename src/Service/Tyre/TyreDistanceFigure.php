<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * How far a tyre has rolled (spec.md §7.17), derived on every read.
 */
final readonly class TyreDistanceFigure
{
    public function __construct(
        /** Kilometres, canonical decimal (3 places). */
        public string $km,
        /** A segment came out negative (the odometer went backwards) and counts as 0. */
        public bool $flagged = false,
    ) {
    }
}
