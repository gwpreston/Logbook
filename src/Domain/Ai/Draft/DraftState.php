<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Draft;

/**
 * Where a draft's card stands (spec.md §7.26).
 */
enum DraftState: string
{
    case Waiting = 'waiting';
    case Added = 'added';
    case Undone = 'undone';
    case Discarded = 'discarded';
    case Expired = 'expired';
}
