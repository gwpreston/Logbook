<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 36.4 (spec.md §6 NotificationChannel, §7.11 *What each channel
 * receives*; #234): `notification_channels.categories`, the categories a
 * personal channel receives as a comma list (`due,overdue,digest`). Null
 * means all of them, so every existing channel keeps receiving everything.
 *
 * Email's categories and quiet hours live in the user's `notifications`
 * setting (JSON), so they need no column. Rolling back drops the column:
 * every channel receives everything again.
 */
final class AddNotificationChannelCategories extends AbstractMigration
{
    public function change(): void
    {
        $this->table('notification_channels')
            ->addColumn('categories', 'string', ['limit' => 100, 'null' => true, 'after' => 'switched_off_at'])
            ->update();
    }
}
