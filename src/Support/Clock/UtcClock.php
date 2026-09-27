<?php

declare(strict_types=1);

namespace Logbook\Support\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The application clock. Always UTC: storage is UTC, and conversion to the
 * user's time zone happens only at the display edge.
 */
final class UtcClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
