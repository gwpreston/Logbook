<?php

declare(strict_types=1);

namespace Logbook\Domain\Ai;

/**
 * How a logged AI request ended (spec.md §6 AiRequest).
 */
enum Outcome: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
    /** Stopped by Logbook before anything was sent (limits, lock, acknowledgement). */
    case Refused = 'refused';
}
