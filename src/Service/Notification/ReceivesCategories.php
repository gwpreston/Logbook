<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * A channel the user chooses categories for (spec.md §7.11 *What each
 * channel receives*, Phase 36.4): email and every personal channel. A
 * channel without it (the server's webhook) receives everything.
 */
interface ReceivesCategories
{
    public function categories(NotificationPreferences $preferences): ChannelCategories;
}
