<?php

declare(strict_types=1);

namespace Logbook\Domain\Access;

/**
 * Install-wide settings and tools, as opposed to one's own preferences
 * (spec.md §5 *Access policy*). Phase 19 adds ManageUsers.
 */
enum InstanceAbility: string
{
    case ManageModules = 'manage_modules';
    case Backup = 'backup';
    case Restore = 'restore';
    case ManageNotifications = 'manage_notifications';
}
