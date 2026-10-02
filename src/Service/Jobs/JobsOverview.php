<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Repository\JobRunRepository;
use Logbook\Support\Http\AbsoluteUrl;
use Psr\Clock\ClockInterface;

/**
 * What Settings → Jobs shows (spec.md §7.30): each job with its schedule,
 * last and next run; the recent runs; the scheduler's health; *How jobs
 * run*; scheduled backups.
 */
final readonly class JobsOverview
{
    public const int RECENT = 25;

    public function __construct(
        private JobRegistry $registry,
        private JobRunner $runner,
        private JobRunRepository $runs,
        private SchedulerHealth $health,
        private JobSettings $settings,
        private AbsoluteUrl $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function page(): array
    {
        $now = $this->clock->now();
        $jobs = [];
        foreach ($this->registry->all() as $job) {
            $interval = $job->interval();
            $jobs[] = [
                'name' => $job->name(),
                'schedule' => $this->schedule($interval),
                'last' => $this->runs->latest($job->name()),
                'off' => $interval === null,
                'next' => $interval === null ? null : $this->runner->nextRun($job, $now),
            ];
        }

        return [
            'jobs' => $jobs,
            'recent' => $this->runs->recent(self::RECENT),
            'newest_id' => $this->runs->newestId(),
            'health' => [
                'stale' => $this->health->isStale(),
                'last_pass' => $this->health->lastPass(),
                'minutes' => intdiv($this->health->interval(), 60),
                'cron_line' => $this->health->cronLine(),
            ],
            'triggers' => [
                'page_visit' => $this->settings->pageVisits(),
                'url' => $this->settings->url(),
                'has_token' => $this->settings->hasUrlToken(),
                'url_shape' => $this->urls->route('scheduler.url', ['token' => 'TOKEN']),
            ],
            'backup' => [
                'schedule' => $this->settings->backupSchedule(),
                'keep' => $this->settings->backupKeep(),
                'schedules' => BackupSchedule::cases(),
                'keep_min' => BackupSchedule::KEEP_MIN,
                'keep_max' => BackupSchedule::KEEP_MAX,
            ],
        ];
    }

    /**
     * The URL for a new token, shown once.
     */
    public function urlFor(string $token): string
    {
        return $this->urls->route('scheduler.url', ['token' => $token]);
    }

    /**
     * @return array{key: string, params: array<string, int>}
     */
    private function schedule(?int $interval): array
    {
        return match (true) {
            $interval === null => ['key' => 'jobs.schedule.off', 'params' => []],
            $interval === 0 => [
                'key' => 'jobs.schedule.every_pass',
                'params' => ['minutes' => intdiv($this->health->interval(), 60)],
            ],
            $interval === 3600 => ['key' => 'jobs.schedule.hourly', 'params' => []],
            $interval === 86400 => ['key' => 'jobs.schedule.daily', 'params' => []],
            $interval === 604800 => ['key' => 'jobs.schedule.weekly', 'params' => []],
            default => ['key' => 'jobs.schedule.minutes', 'params' => ['minutes' => intdiv($interval, 60)]],
        };
    }
}
