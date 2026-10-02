<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;
use Logbook\Domain\Job\JobRun;
use Logbook\Repository\JobRunRepository;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * Whether the scheduler runs (spec.md §7.30 *Scheduler health*): the last
 * pass is the newest finished run of any trigger but `manual`, and it is
 * stale once none has finished within twice SCHEDULER_INTERVAL.
 */
final readonly class SchedulerHealth
{
    public function __construct(
        private JobRunRepository $runs,
        private AppSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    public function lastPass(): ?JobRun
    {
        return $this->runs->lastPass();
    }

    public function isStale(): bool
    {
        $last = $this->lastPass();
        $at = $last === null ? null : ($last->finishedAt ?? $last->startedAt);

        return $at === null
            || $this->clock->now()->getTimestamp() - $at->getTimestamp() > 2 * $this->settings->schedulerInterval;
    }

    /**
     * Whether a pass is due by SCHEDULER_INTERVAL (the page-visit beacon).
     */
    public function isPassDue(): bool
    {
        $last = $this->lastPass();

        return $last === null
            || $this->clock->now()->getTimestamp() - $last->startedAt->getTimestamp() >= $this->settings->schedulerInterval;
    }

    public function lastPassAt(): ?DateTimeImmutable
    {
        return $this->lastPass()?->startedAt;
    }

    public function interval(): int
    {
        return $this->settings->schedulerInterval;
    }

    /**
     * The crontab line for this install: its path and interval (whole
     * minutes, every 1 to 59; anything else is every 15 minutes).
     */
    public function cronLine(): string
    {
        $minutes = intdiv($this->settings->schedulerInterval, 60);
        $every = $this->settings->schedulerInterval % 60 === 0 && $minutes >= 1 && $minutes <= 59 ? $minutes : 15;

        $dir = $this->settings->rootDir;

        return sprintf(
            '%s * * * * cd %s && php bin/run-scheduled-tasks.php',
            $every === 1 ? '*' : '*/' . $every,
            preg_match('#^[A-Za-z0-9_./-]+$#', $dir) === 1 ? $dir : escapeshellarg($dir),
        );
    }
}
