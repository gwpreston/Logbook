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
}
