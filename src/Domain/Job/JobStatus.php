<?php

declare(strict_types=1);

namespace Logbook\Domain\Job;

/**
 * How a job run went (spec.md §6 JobRun).
 */
enum JobStatus: string
{
    case Running = 'running';
    case Ok = 'ok';
    /** Finished, but part of the work failed (say, one account's reminders). */
    case Partial = 'partial';
    case Failed = 'failed';
    /** The job was already running; nothing was done. */
    case SkippedLocked = 'skipped_locked';
    /** Left `running` by a process that died. */
    case Interrupted = 'interrupted';

    public function labelKey(): string
    {
        return 'jobs.status.' . $this->value;
    }

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }

    /**
     * Whether the run did the job's work (or tried to): what *due* and
     * failure streaks are judged by.
     */
    public function didWork(): bool
    {
        return in_array($this, [self::Ok, self::Partial, self::Failed, self::Interrupted], true);
    }
}
