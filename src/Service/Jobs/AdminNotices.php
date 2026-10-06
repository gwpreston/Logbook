<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\User\User;
use Logbook\Repository\JobRunRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Demo\DemoMode;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Updates\UpdateBanner;
use Logbook\Service\Updates\UpdateSettings;
use Logbook\Support\Version\SemVer;
use Psr\Clock\ClockInterface;

/**
 * The dashboard's admin notice area (spec.md §7.30 *Admin notices*): the
 * update banner (§7.31), the scheduler warning and job failure streaks,
 * for admins only. *Dismiss* hides a notice for 24 hours for that admin;
 * it comes back after that while its problem lasts. The update banner's
 * *Dismiss* is for good, for that version.
 */
final readonly class AdminNotices
{
    public const string SETTING = 'notices.dismissed';
    public const int DISMISS_SECONDS = 86400;
    public const string SCHEDULER = 'scheduler';
    public const string DEMO_REFUSED = 'demo_refused';

    public function __construct(
        private InstanceAccess $access,
        private SchedulerHealth $health,
        private JobRunRepository $runs,
        private JobFailureAlerts $alerts,
        private SettingRepository $settings,
        private ClockInterface $clock,
        private UpdateBanner $update,
        private UpdateSettings $updates,
        private ?DemoMode $demo = null,
    ) {
    }

    /**
     * @return list<AdminNotice>
     */
    public function for(User $user): array
    {
        if (!$this->access->can($user, InstanceAbility::RunJobs)) {
            return [];
        }

        $notices = [];
        $update = $this->update->for($user);
        if ($update !== null) {
            $notices[] = $update;
        }
        // DEMO_MODE set on a database that is not a demo (spec.md §7.36): nothing was changed.
        $refusal = $this->demo?->status()->refusal;
        if ($refusal !== null) {
            $notices[] = new AdminNotice(
                key: self::DEMO_REFUSED,
                messageKey: 'notices.demo_refused.' . $refusal->value,
                params: [],
                linkRoute: null,
                linkData: [],
                linkLabelKey: '',
                level: 'error',
            );
        }
        if ($this->health->isStale()) {
            $notices[] = new AdminNotice(
                key: self::SCHEDULER,
                messageKey: $this->health->lastPassAt() === null ? 'notices.scheduler.never' : 'notices.scheduler.stale',
                params: ['time' => $this->health->lastPassAt()],
                linkRoute: 'settings.jobs',
                linkData: [],
                linkLabelKey: 'notices.scheduler.link',
            );
        }
        // The jobs that have runs (not the registry: building every job for each page is wasteful).
        foreach ($this->runs->jobNames() as $job) {
            $failed = $this->alerts->streak($job);
            if ($failed !== null) {
                $notices[] = new AdminNotice(
                    key: 'job_failed.' . $job,
                    messageKey: 'notices.job_failed.message',
                    params: ['job' => $job],
                    linkRoute: 'settings.jobs.run',
                    linkData: ['run' => $failed->id],
                    linkLabelKey: 'notices.job_failed.link',
                    level: 'error',
                );
            }
        }

        $dismissed = $this->dismissed($user);
        $now = $this->clock->now()->getTimestamp();

        return array_values(array_filter(
            $notices,
            static fn (AdminNotice $n): bool => !isset($dismissed[$n->key])
                || $now - $dismissed[$n->key] >= self::DISMISS_SECONDS,
        ));
    }

    /**
     * Hide the notice for 24 hours for this admin, or the update banner
     * for that version for good. Unknown keys are ignored.
     */
    public function dismiss(User $user, string $key): void
    {
        if (str_starts_with($key, UpdateBanner::KEY_PREFIX)) {
            $version = SemVer::parseRelease(substr($key, strlen(UpdateBanner::KEY_PREFIX)));
            if ($version !== null) {
                $this->updates->dismiss($user, $version);
            }

            return;
        }
        if (preg_match('/^(scheduler|demo_refused|job_failed\.[a-z_]+)$/D', $key) !== 1) {
            return;
        }
        $stored = [];
        $now = $this->clock->now();
        foreach ($this->dismissed($user) as $name => $at) {
            // Expired entries are dropped as the list is rewritten.
            if ($now->getTimestamp() - $at < self::DISMISS_SECONDS) {
                $stored[$name] = (new DateTimeImmutable('@' . $at))->format(DATE_ATOM);
            }
        }
        $stored[$key] = $now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $this->settings->save(self::SETTING, $stored, SettingScope::User, $user->id);
    }

    /**
     * @return array<string, int> key → when it was dismissed (Unix time)
     */
    private function dismissed(User $user): array
    {
        $value = $this->settings->find(self::SETTING, SettingScope::User, $user->id)?->value;
        $dismissed = [];
        foreach (is_array($value) ? $value : [] as $key => $at) {
            if (is_string($key) && is_string($at)) {
                $time = strtotime($at);
                if ($time !== false) {
                    $dismissed[$key] = $time;
                }
            }
        }

        return $dismissed;
    }
}
