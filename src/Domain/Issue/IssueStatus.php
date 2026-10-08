<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

/**
 * Where an issue stands (spec.md §7.37 *Statuses*): noticed and not dealt
 * with, deliberately kept an eye on, or fixed.
 */
enum IssueStatus: string
{
    case Open = 'open';
    case Watching = 'watching';
    case Fixed = 'fixed';

    public function labelKey(): string
    {
        return 'issue.status.' . $this->value;
    }

    /**
     * Open and watching issues still need the owner (the overview card, the
     * fleet page, the *Fixes* checklist).
     */
    public function isUnresolved(): bool
    {
        return $this !== self::Fixed;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'report',
            self::Watching => 'visibility',
            self::Fixed => 'check_circle',
        };
    }
}
