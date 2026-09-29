<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * Why a vehicle's tyre changes cannot be replayed in order (spec.md §7.17).
 * Each names its message (`tyre.error.sequence.<value>`).
 */
enum TyreSequenceProblem: string
{
    /** Two tyres would be at one position. */
    case Occupied = 'occupied';
    /** Fitted while it was already fitted. */
    case AlreadyFitted = 'already_fitted';
    /** Moved, repaired or taken off while it was not fitted. */
    case NotFitted = 'not_fitted';
    /** Touched after it was retired. */
    case Retired = 'retired';
}
