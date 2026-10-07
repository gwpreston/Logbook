<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Notification secrets (spec.md §6 NotificationSecret; Phase 36.1): the
 * email server's password, and from Phase 36.2 members' channel tokens.
 *
 * - `owner_user_id`: null for the installation's, else the member's
 *   (deleted with them);
 * - `name`: `smtp_password`, …;
 * - `value`: sealed (`v1:` …) or, for the installation's only, `env:NAME`.
 *
 * The unique index covers members' rows; engines let several NULL owners
 * through it, so the repository replaces an installation row in one
 * transaction instead. Never backed up. Rolling back drops it (the SMTP
 * password is lost; the `email.smtp` setting is kept).
 */
final class CreateNotificationSecrets extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_secrets')
            ->addColumn('owner_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['owner_user_id', 'name'], ['unique' => true, 'name' => 'notification_secrets_owner_name_uq'])
            ->addForeignKey('owner_user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'notification_secrets_owner_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('notification_secrets')->drop()->save();
    }
}
