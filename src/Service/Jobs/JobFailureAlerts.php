<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Domain\Job\JobRun;
use Logbook\Domain\Job\JobStatus;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\NotificationComposer;
use Logbook\Service\Notification\NotificationDispatcher;
use Logbook\Service\Notification\Recipient;
use Logbook\Service\Reminder\ReminderSettingsStore;

/**
 * Failure alerts (spec.md §7.30, decided 2026-10-02, #107): a job whose
 * last two runs with an outcome both failed is a streak. Admins see it as
 * a notice while it lasts; once per streak each admin is also sent a
 * `job_failed` notification through their own channels. An ok or partial
 * run ends the streak.
 */
final readonly class JobFailureAlerts
{
    public const string SETTING = 'jobs.failure_alerts';

    public function __construct(
        private JobRunRepository $runs,
        private SettingRepository $settings,
        private UserRepository $users,
        private ReminderSettingsStore $preferences,
        private NotificationComposer $composer,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function afterRun(JobRun $run): void
    {
        if (in_array($run->status, [JobStatus::Ok, JobStatus::Partial], true)) {
            $this->forget($run->job);

            return;
        }
        if ($run->status !== JobStatus::Failed || $this->streak($run->job) === null) {
            return;
        }
        $alerted = $this->alerted();
        if (isset($alerted[$run->job])) {
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
            $this->dispatcher->dispatch(
                $this->composer->jobFailed($user, $run),
                Recipient::of($user, $preferences),
                $preferences,
            );
        }
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
