<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * A named piece of background work (spec.md §5 *Jobs*, §7.30). The CLI,
 * the scheduler's triggers and *Run now* all run it through JobRunner.
 */
interface Job
{
    /** The job's name: `reminders`, `digest`, `cleanup`, `backup`. */
    public function name(): string;

    /**
     * Seconds between runs: 0 for every scheduler pass, null when it only
     * runs by hand.
     */
    public function interval(): ?int;

    public function run(JobContext $context): JobResult;
}
