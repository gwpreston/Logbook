<?php

declare(strict_types=1);

namespace Logbook\Service\Scheduler;

use Closure;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Service\Jobs\CleanupJob;
use Logbook\Service\Jobs\DigestJob;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Service\Jobs\RemindersJob;
use Psr\Log\LoggerInterface;

/**
 * A scheduler pass (spec.md §5 *Jobs*, §7.30): what
 * bin/run-scheduled-tasks.php runs (cron every 15 minutes, or the Docker
 * entrypoint's loop; spec.md §10), and the page-visit and URL triggers.
 * Every due job runs through JobRunner; the summary adds up their counts.
 */
final readonly class ScheduledTasks
{
    public function __construct(
        private JobRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param (Closure(string): void)|null $sink each output line, as it comes
     * @param (Closure(): bool)|null $stillDue checked under the pass lock
     */
    public function run(JobTrigger $trigger = JobTrigger::Cron, ?Closure $sink = null, ?Closure $stillDue = null): TaskSummary
    {
        $runs = $this->runner->pass($trigger, $sink, $stillDue);
        if ($runs === null) {
            return TaskSummary::locked();
        }

        $counts = ['users' => 0, 'reminders' => 0, 'digests' => 0, 'failures' => 0, 'ai' => 0, 'failed_jobs' => 0];
        foreach ($runs as $run) {
            $counts['failed_jobs'] += in_array($run->status, [JobStatus::Failed, JobStatus::Partial], true) ? 1 : 0;
            $result = $run->counts;
            match ($run->job) {
                RemindersJob::NAME => $counts = [
                    'users' => $result['users'] ?? 0,
                    'reminders' => $result['sent'] ?? 0,
                    'failures' => $counts['failures'] + ($result['failures'] ?? 0),
                ] + $counts,
                DigestJob::NAME => $counts = [
                    'digests' => $result['sent'] ?? 0,
                    'failures' => $counts['failures'] + ($result['failures'] ?? 0),
                ] + $counts,
                CleanupJob::NAME => $counts = ['ai' => $result['usage'] ?? 0] + $counts,
                default => null,
            };
        }

        $summary = new TaskSummary(
            $counts['users'],
            $counts['reminders'],
            $counts['digests'],
            $counts['failures'],
            $counts['ai'],
            jobsFailed: $counts['failed_jobs'],
        );
        $this->logger->info('Scheduled tasks: {summary}', ['summary' => $summary->describe()]);

        return $summary;
    }
}
