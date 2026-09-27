<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

/**
 * How urgent a recurring job is right now.
 */
enum DueStatus: string
{
    /** Nothing to measure against yet (no last-done point, or no odometer for a distance-only schedule). */
    case Unknown = 'unknown';
    case Ok = 'ok';
    case Soon = 'soon';
    case Overdue = 'overdue';

    /**
     * Sort weight: most urgent first.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::Soon => 1,
            self::Ok => 2,
            self::Unknown => 3,
        };
    }

    /**
     * Modifier of the .pill status colours.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Overdue => 'overdue',
            self::Soon => 'soon',
            self::Ok => 'valid',
            self::Unknown => '',
        };
    }
}
