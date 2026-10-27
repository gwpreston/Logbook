<?php

declare(strict_types=1);

namespace Logbook\Support\Date;

/**
 * What a wall-clock time typed in a time zone is (see LocalTime::wallClock()).
 */
enum WallClock
{
    /** One instant: the usual case. */
    case Exists;

    /** In the hour skipped when the clocks go forward: no such time. */
    case Skipped;

    /** In the hour repeated when the clocks go back: two instants. */
    case Repeated;
}
