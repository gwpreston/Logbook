<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;

/**
 * A job that names the time it is next due instead of counting its
 * interval from the last run (spec.md §5 *Jobs*): `update_check`'s daily
 * minute and a rate limit's wait. `interval()` still describes its
 * schedule on the Jobs page, and null still means off.
 */
interface TimedJob extends Job
{
    /**
     * The earliest time the job is due, given when its last finished run
     * (any status but `skipped_locked`) started.
     */
    public function dueAt(?DateTimeImmutable $lastStarted, DateTimeImmutable $now): DateTimeImmutable;
}
