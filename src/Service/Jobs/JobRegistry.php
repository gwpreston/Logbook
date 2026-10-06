<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

/**
 * Every job, in the order a scheduler pass runs them (spec.md §5 *Jobs*):
 * `reminders` before `digest`, as the combined task sent them.
 */
final readonly class JobRegistry
{
    /** @var array<string, Job> */
    private array $jobs;

    /**
     * @param list<Job> $jobs
     */
    public function __construct(array $jobs)
    {
        $byName = [];
        foreach ($jobs as $job) {
            $byName[$job->name()] = $job;
        }
        $this->jobs = $byName;
    }

    /**
     * @return list<Job>
     */
    public function all(): array
    {
        return array_values(array_filter($this->jobs, self::listed(...)));
    }

    public function get(string $name): ?Job
    {
        $job = $this->jobs[$name] ?? null;

        return $job !== null && self::listed($job) ? $job : null;
    }

    private static function listed(Job $job): bool
    {
        return !$job instanceof ConditionalJob || $job->isListed();
    }
}
