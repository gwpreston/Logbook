<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

/**
 * A tyre at or under the owner's legal minimum (spec.md §7.17): measured,
 * or only estimated, which asks for a check rather than claiming it.
 */
enum TyreLegalFlag: string
{
    case Below = 'below';
    case MayBeBelow = 'may_be_below';
}
