<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai\Ask;

/**
 * An answer's *Helpful* / *Not right* mark (spec.md §7.26).
 */
enum FeedbackMark: string
{
    case Helpful = 'helpful';
    case NotRight = 'not_right';
}
