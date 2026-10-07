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
    /** Settings → Delivery: the email server (Phase 36.1). */
    case ManageNotifications = 'manage_notifications';
    /** Settings → Users: invitations, admins, disabling and deleting (Phase 19). */
    case ManageUsers = 'manage_users';
    /** Settings → AI: connections, models and tasks (Phase 26.1). */
    case ManageAi = 'manage_ai';
    /** Settings → Jobs: runs, *Run now*, triggers and scheduled backups (Phase 28.1). */
    case RunJobs = 'run_jobs';
    /** Settings → Fuel prices: the provider and its credentials (Phase 30.2). */
    case ManageFuelPrices = 'manage_fuel_prices';

    /**
     * Whether a user without the ability gets 404 rather than 403, so the
     * page's existence is not revealed (Settings → AI, spec.md §7.25,
     * Settings → Jobs, §7.30, Settings → Fuel prices, §7.34, and Settings →
     * Delivery, §7.11).
     */
    public function isHidden(): bool
    {
        return in_array($this, [self::ManageAi, self::RunJobs, self::ManageFuelPrices, self::ManageNotifications], true);
    }
}
