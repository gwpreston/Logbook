<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * Where a tyre is (spec.md §7.17). Replayed from the vehicle's tyre changes
 * and stored on the tyre so lists can query it; never edited directly.
 */
enum TyreStatus: string
{
    case Fitted = 'fitted';
    case Stored = 'stored';
    case Retired = 'retired';
}
