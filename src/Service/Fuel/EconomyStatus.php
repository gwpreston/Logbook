<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

/**
 * What a fill-up contributes to the consumption figures.
 */
enum EconomyStatus: string
{
    /** A full fill that closes a full-to-full segment: consumption is known. */
    case Measured = 'measured';
    /** A partial fill after a full one: counted when the next full fill closes the segment. */
    case Partial = 'partial';
    /**
     * A full fill that starts measuring: the first one, or the first after a
     * missed fill-up or an unusable odometer reading. The fuel it adds was
     * burned before it, so it is never part of a segment.
     */
    case Baseline = 'baseline';
    /** A partial fill with no full fill before it to measure from. */
    case Unmeasured = 'unmeasured';
    /** A full fill whose odometer is not beyond the segment's start: measuring restarts here. */
    case Invalid = 'invalid';
}
