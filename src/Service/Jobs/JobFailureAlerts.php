<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Reminder\ReminderSettingsStore;
use Psr\Clock\ClockInterface;

/**
 * Failure alerts (spec.md §7.30, decided 2026-10-02, #107): a job whose
 * last two runs with an outcome both failed is a streak. Admins see it as
 * a notice while it lasts; once per streak each admin is also sent a
 * `job_failed` notification through their own channels. An ok or partial
 * run ends the streak.
 *
 * Phase 36.4 (spec.md §7.11): an admin inside their quiet hours gets a
 * held entry instead (HeldJobFailures). After every run, each admin out
 * of quiet hours is sent their held jobs whose streak still lasts, as one
 * message; the others are dropped.
 */
final readonly class JobFailureAlerts
{
    public const string SETTING = 'jobs.failure_alerts';
    public const string HELD = HeldJobFailures::NAME;

    public function __construct(
        private JobRunRepository $runs,
        private SettingRepository $settings,
        private HeldJobFailures $held,
        private UserRepository $users,
        private ReminderSettingsStore $preferences,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function afterRun(JobRun $run): void
    {
        $this->alert($run);
        $this->sendHeld();
    }

    private function alert(JobRun $run): void
    {
        if (in_array($run->status, [JobStatus::Ok, JobStatus::Partial], true)) {
            $this->forget($run->job);

            return;
        }
        if ($run->status !== JobStatus::Failed || $this->streak($run->job) === null) {
            return;
        }
        $alerted = $this->alerted();
        // A marker whose run is gone (a restore empties job_runs, not settings) is stale.
        if (isset($alerted[$run->job]) && $this->runs->find($alerted[$run->job]) !== null) {
            return;
        }
        // Recorded first: a channel that hangs or throws never sends it twice.
        $alerted[$run->job] = $run->id;
        $this->settings->save(self::SETTING, $alerted);

        foreach ($this->users->listAll() as $user) {
            if (!$user->isAdmin || !$user->isActive()) {
                continue;
            }
            $preferences = $this->preferences->notificationPreferences($user->id);
            if ($this->isQuiet($user, $preferences)) {
                $this->held->hold($user->id, $run->job, $run->id);
                continue;
            }
            $this->dispatcher->dispatch(
                $this->composer->jobFailed($user, $run),
                Recipient::of($user),
                $preferences,
            );
        }
    }

    /**
     * Each admin's held failures once their quiet hours are over: the jobs
     * still failing, as one message (#253); a streak that ended is dropped
     * (#252).
     */
    private function sendHeld(): void
    {
        foreach ($this->users->listAll() as $user) {
            if (!$user->isAdmin) {
                continue;
            }
            if ($this->held->of($user->id) === []) {
                continue;
            }
            $preferences = $this->preferences->notificationPreferences($user->id);
            if ($user->isActive() && $this->isQuiet($user, $preferences)) {
                continue;
            }
            // Only the run that removed them sends them (#269).
            $held = $this->held->take($user->id);
            if ($held === [] || !$user->isActive()) {
                continue;
            }
            $runs = array_values(array_filter(array_map($this->streak(...), array_keys($held))));
            if ($runs !== []) {
                $this->dispatcher->dispatch($this->composer->jobsFailed($user, $runs), Recipient::of($user), $preferences);
            }
        }
    }

    private function isQuiet(User $user, NotificationPreferences $preferences): bool
    {
        return $preferences->quiet?->contains($this->clock->now(), $user->preferences->timeZone()) === true;
    }

    /**
     * The job's latest failed run when its last two outcomes both failed.
     */
    public function streak(string $job): ?JobRun
    {
        $outcomes = $this->runs->latestOutcomes($job, 2);
        if (count($outcomes) < 2) {
            return null;
        }
        foreach ($outcomes as $outcome) {
            if ($outcome->status !== JobStatus::Failed) {
                return null;
            }
        }

        return $outcomes[0];
    }

    private function forget(string $job): void
    {
        $alerted = $this->alerted();
        if (isset($alerted[$job])) {
            unset($alerted[$job]);
            $this->settings->save(self::SETTING, $alerted);
        }
    }

    /**
     * @return array<string, int>
     */
    private function alerted(): array
    {
        $value = $this->settings->find(self::SETTING)?->value;
        $alerted = [];
        foreach (is_array($value) ? $value : [] as $job => $id) {
            if (is_string($job) && is_int($id)) {
                $alerted[$job] = $id;
            }
        }

        return $alerted;
    }
}
