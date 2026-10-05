<?php

declare(strict_types=1);

namespace Logbook\Support\Clock;

/**
 * Waits. A seam so code that pads a response to a fixed time (spec.md §7.9
 * *Forgotten password*) is tested with a recording sleeper, not wall time.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
