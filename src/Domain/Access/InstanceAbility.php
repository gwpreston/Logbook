<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * Install-wide settings and tools, as opposed to one's own preferences
 * (spec.md §5 *Access policy*).
 */
enum InstanceAbility: string
{
    case ManageModules = 'manage_modules';
    case Backup = 'backup';
    case Restore = 'restore';
    case ManageNotifications = 'manage_notifications';
    /** Settings → Users: invitations, admins, disabling and deleting (Phase 19). */
    case ManageUsers = 'manage_users';
    /** Settings → AI: connections, models and tasks (Phase 26.1). */
    case ManageAi = 'manage_ai';
    /** Settings → Jobs: runs, *Run now*, triggers and scheduled backups (Phase 28.1). */
    case RunJobs = 'run_jobs';

    /**
     * Whether a user without the ability gets 404 rather than 403, so the
     * page's existence is not revealed (Settings → AI, spec.md §7.25, and
     * Settings → Jobs, §7.30).
     */
    public function isHidden(): bool
    {
        return $this === self::ManageAi || $this === self::RunJobs;
    }
}
