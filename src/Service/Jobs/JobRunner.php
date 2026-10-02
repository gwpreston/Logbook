<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Closure;
use DateTimeImmutable;
use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\Job\JobTrigger;
use Logbook\Repository\JobRunRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Runs jobs for every trigger (spec.md §5 *Jobs*, §7.30): takes the job's
 * lock, writes a `job_runs` row, runs it with its output captured, stores
 * the result and releases the lock. A pass runs every due job, in the
 * registry's order, under the pass lock.
 */
final readonly class JobRunner
{
    /** A `running` row this old, with its lock free, was left by a dead process. */
    public const int INTERRUPTED_AFTER = 3600;
    /** A pass runs a job slightly early rather than a whole pass late. */
    private const int SLACK = 60;

    public function __construct(
        private JobRegistry $registry,
        private JobRunRepository $runs,
        private JobLocks $locks,
        private RunCapture $capture,
        private OutputRedactor $redactor,
        private JobFailureAlerts $alerts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Run one job now, whether or not it is due.
     *
     * @param (Closure(string): void)|null $sink each output line, as it comes
     * @param int|null $timeLimit seconds before the job is asked to stop
     */
    public function run(
        Job $job,
        JobTrigger $trigger,
        ?int $userId = null,
        ?Closure $sink = null,
        ?int $timeLimit = null,
    ): JobRun {
        $lock = $this->locks->acquire($job->name());
        if ($lock === null) {
            return $this->skipped($job, $trigger, $userId, $sink);
        }

        try {
            $now = $this->clock->now();
            $this->runs->interruptBefore(
                $job->name(),
                $now->modify(sprintf('-%d seconds', self::INTERRUPTED_AFTER)),
                $now,
                $this->translator->trans('jobs.summary.interrupted'),
            );
            $id = $this->runs->start($job->name(), $trigger, $userId, $now);
            $log = new RunLog(
                $this->redactor,
                $this->clock,
                fn (string $output) => $this->runs->writeOutput($id, $output),
                $sink,
            );
            $deadline = $timeLimit === null ? null : $now->getTimestamp() + max(1, $timeLimit - 10);
            $context = new JobContext(
                $this->logger,
                fn (): bool => $deadline !== null && $this->clock->now()->getTimestamp() >= $deadline,
            );

            $this->capture->begin($log);
            try {
                $this->logger->debug('Starting {job} ({trigger}).', ['job' => $job->name(), 'trigger' => $trigger->value]);
                try {
                    $result = $job->run($context);
                } catch (Throwable $e) {
                    $this->logger->error('{job} failed: {message}', [
                        'job' => $job->name(),
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                    $result = JobResult::failed($this->redactor->redact($e->getMessage()));
                }
                $summary = $this->redactor->redact($result->summary);
                $this->logger->log(
                    $result->status === JobStatus::Ok ? 'info' : ($result->status === JobStatus::Partial ? 'warning' : 'error'),
                    'Finished {job}: {status}. {summary}',
                    ['job' => $job->name(), 'status' => $result->status->value, 'summary' => $summary],
                );
            } finally {
                $this->capture->end();
            }
            $this->runs->finish($id, $result->status, $summary, $log->output(), $this->clock->now());
        } finally {
            $this->locks->release($lock);
        }

        $run = ($this->runs->find($id) ?? throw new RuntimeException('The job run was not recorded.'))
            ->withCounts($result->counts);
        try {
            $this->alerts->afterRun($run);
        } catch (Throwable $e) {
            $this->logger->error('Job failure alerts failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }

        return $run;
    }

    /**
     * A scheduler pass: every due job, in order. Null when another pass
     * holds the pass lock.
     *
     * @param (Closure(string): void)|null $sink
     * @param (Closure(): bool)|null $stillDue checked again once the pass
     *        lock is held (a beacon: another pass may just have finished)
     * @return list<JobRun>|null
     */
    public function pass(JobTrigger $trigger, ?Closure $sink = null, ?Closure $stillDue = null): ?array
    {
        $lock = $this->locks->acquire(JobLocks::PASS);
        if ($lock === null) {
            return null;
        }
        try {
            if ($stillDue !== null && !$stillDue()) {
                return [];
            }
            $runs = [];
            foreach ($this->registry->all() as $job) {
                if ($this->isDue($job, $this->clock->now())) {
                    $runs[] = $this->run($job, $trigger, null, $sink);
                }
            }

            return $runs;
        } finally {
            $this->locks->release($lock);
        }
    }

    public function isDue(Job $job, DateTimeImmutable $now): bool
    {
        $interval = $job->interval();
        if ($interval === null) {
            return false;
        }
        if ($interval === 0) {
            return true;
        }
        $last = $this->runs->latestWorked($job->name());

        return $last === null
            || $now->getTimestamp() - $last->startedAt->getTimestamp() >= $interval - self::SLACK;
    }

    /**
     * When the job is next expected to run: null for "with the next pass"
     * (every pass, or already due), else the time its interval is up.
     */
    public function nextRun(Job $job, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $interval = $job->interval();
        if ($interval === null || $interval === 0 || $this->isDue($job, $now)) {
            return null;
        }
        $last = $this->runs->latestWorked($job->name());

        return $last?->startedAt->modify(sprintf('+%d seconds', $interval));
    }

    /**
     * @param (Closure(string): void)|null $sink
     */
    private function skipped(Job $job, JobTrigger $trigger, ?int $userId, ?Closure $sink): JobRun
    {
        $holder = $this->runs->running($job->name());
        $summary = $this->translator->trans('jobs.summary.locked', [
            'run' => $holder->id ?? 0,
            'trigger' => $holder?->trigger->value ?? '',
        ]);
        if ($sink !== null) {
            $sink($summary);
        }
        $id = $this->runs->recordSkipped($job->name(), $trigger, $userId, $this->clock->now(), $summary, $summary);

        return $this->runs->find($id) ?? throw new RuntimeException('The job run was not recorded.');
    }
}
