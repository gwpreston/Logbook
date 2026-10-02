<?php

declare(strict_types=1);

namespace Logbook\Domain\Job;

/**
 * What started a job run (spec.md §6 JobRun, §7.30).
 */
enum JobTrigger: string
{
    /** bin/run-scheduled-tasks.php from cron (or by hand). */
    case Cron = 'cron';
    /** The Docker entrypoint's loop. */
    case Docker = 'docker';
    /** A signed-in page's beacon (*On page visits*). */
    case PageVisit = 'page_visit';
    /** The secret URL (*External URL*). */
    case Url = 'url';
    /** *Run now*, or bin/run-job.php. */
    case Manual = 'manual';

    public function labelKey(): string
    {
        return 'jobs.trigger.' . $this->value;
    }
}
