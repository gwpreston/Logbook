<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Tyre\TyreStatus;

/**
 * Where a tyre is after the replay (spec.md §7.17): stored on the tyre, plus
 * the position it last had, which a swap puts it back to.
 */
final readonly class TyreState
{
    public function __construct(
        public TyreStatus $status,
        public ?TyrePosition $position = null,
        public ?TyrePosition $lastPosition = null,
    ) {
    }
}
