<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\Tyre;

/**
 * A depth saved more than TyreWear::DEEPER_MM deeper than the tyre's
 * previous one (spec.md §7.17): saved all the same, with a notice to check
 * the reading.
 */
final readonly class DeeperReading
{
    public function __construct(
        public Tyre $tyre,
        /** The new depth, mm. */
        public string $treadMm,
        public TyreMeasurement $previous,
    ) {
    }
}
