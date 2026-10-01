<?php

declare(strict_types=1);

namespace Logbook\Domain\Attention;

/**
 * The two groups of *Needs attention* (spec.md §7.24): work that is overdue
 * now, and data that looks wrong. Shown as text, never as colour alone.
 */
enum AttentionSeverity: string
{
    /** Work or paperwork that is overdue. */
    case Now = 'now';
    /** The data looks wrong, so figures built on it may be too. */
    case Check = 'check';
}
