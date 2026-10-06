<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * A job that exists only sometimes (spec.md §7.30): `demo_reset` is listed,
 * run and found by name only while the demo is active.
 */
interface ConditionalJob extends Job
{
    public function isListed(): bool;
}
