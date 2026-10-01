<?php

declare(strict_types=1);

namespace Logbook\Domain\Incident;

/**
 * Where the insurance claim stands (spec.md §6 Incident).
 */
enum ClaimStatus: string
{
    case NotClaimed = 'not_claimed';
    case Notified = 'notified';
    case Open = 'open';
    case Settled = 'settled';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function labelKey(): string
    {
        return 'incident.claim.' . $this->value;
    }

    /**
     * A claim the insurer was told about (the claims history's *Claims only*).
     */
    public function isClaim(): bool
    {
        return $this !== self::NotClaimed;
    }

    /**
     * Waiting for news from the insurer (spec.md §7.24 *Stalled claim*).
     */
    public function isAwaitingNews(): bool
    {
        return $this === self::Notified || $this === self::Open;
    }
}
