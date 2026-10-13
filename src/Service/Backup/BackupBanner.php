<?php

declare(strict_types=1);

namespace Logbook\Service\Backup;

use Logbook\Domain\Access\InstanceAbility;
use Logbook\Domain\User\User;
use Logbook\Service\Access\InstanceAccess;
use Logbook\Service\Jobs\AdminNotice;
use Logbook\Service\Jobs\BackupSchedule;
use Logbook\Service\Jobs\JobSettings;

/**
 * The backup banner in the dashboard's admin notice area (spec.md §7.13):
 * for a user with `Backup` while the last backup is older than the
 * threshold, or there has never been one. Demo mode never shows it: its
 * InstanceAccess refuses `Backup` (§7.36). AdminNotices applies *Dismiss*.
 */
final readonly class BackupBanner
{
    public const string KEY = 'backup_age';
    /** *Dismiss* hides this notice for 7 days (the others: 24 hours). */
    public const int DISMISS_SECONDS = 7 * 86400;

    public function __construct(
        private InstanceAccess $access,
        private BackupAge $age,
        private JobSettings $jobs,
    ) {
    }

    public function for(User $user): ?AdminNotice
    {
        if (!$this->access->can($user, InstanceAbility::Backup)) {
            return null;
        }
        $status = $this->age->for($user);
        if (!$status->warning) {
            return null;
        }

        $routes = [];
        if ($this->jobs->backupSchedule() === BackupSchedule::Off && $this->access->can($user, InstanceAbility::RunJobs)) {
            $routes[] = [
                'route' => 'settings.jobs',
                'data' => [],
                'labelKey' => 'notices.backup.schedule',
                'fragment' => 'job-backup',
            ];
        }

        // Without PHP's zip extension the download is a 404: the Backup page says why.
        $available = BackupService::isAvailable();

        return new AdminNotice(
            key: self::KEY,
            messageKey: $status->ageDays === null ? 'notices.backup.never' : 'notices.backup.message',
            params: ['days' => $status->ageDays],
            linkRoute: $available ? 'backup.download' : 'backup.index',
            linkData: [],
            linkLabelKey: $available ? 'notices.backup.download' : 'notices.backup.page',
            routes: $routes,
        );
    }
}
