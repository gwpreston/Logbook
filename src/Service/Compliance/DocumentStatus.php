<?php

declare(strict_types=1);

namespace Logbook\Service\Compliance;

/**
 * Where a compliance document stands today.
 */
enum DocumentStatus: string
{
    case Valid = 'valid';
    /** Expires within DocumentState::SOON_DAYS. */
    case Expiring = 'expiring';
    case Expired = 'expired';
    /** Starts in the future (a renewal bought ahead). */
    case Upcoming = 'upcoming';
    /** Has no expiry date. */
    case Open = 'open';
    /** A newer document of the same type has taken over. */
    case Replaced = 'replaced';

    /**
     * Sort weight: what needs attention first.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::Expired => 0,
            self::Expiring => 1,
            self::Valid, self::Open => 2,
            self::Upcoming => 3,
            self::Replaced => 4,
        };
    }

    /**
     * Modifier of the .pill status colours.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Expired => 'overdue',
            self::Expiring => 'soon',
            self::Valid => 'valid',
            self::Upcoming, self::Open, self::Replaced => '',
        };
    }

    public function isCurrent(): bool
    {
        return $this !== self::Replaced;
    }
}
