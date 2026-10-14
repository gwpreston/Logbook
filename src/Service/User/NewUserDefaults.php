<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Reminder\ReminderSettingsStore;

/**
 * What a user created from now on starts with (spec.md §7.8, §7.11): the
 * digest on and the *Household* dashboard layout. Users who exist already
 * have neither written, so nothing changes for them.
 */
final readonly class NewUserDefaults
{
    public function __construct(
        private ReminderSettingsStore $reminders,
        private DashboardLayoutStore $layouts,
    ) {
    }

    public function startNewUser(int $userId): void
    {
        $this->reminders->startNewUser($userId);
        $this->layouts->startNewUser($userId);
    }
}
