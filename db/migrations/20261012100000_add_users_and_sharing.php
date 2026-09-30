<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multiple users and vehicle sharing (spec.md §6 User, VehicleShare,
 * Invitation, ReminderDelivery, *Entry authorship*; Phase 19).
 *
 * - `users.is_admin` / `disabled_at`: every existing user (there is one)
 *   becomes an admin, so nothing changes for them.
 * - `vehicle_shares`, `invitations`, `reminder_deliveries`.
 * - `created_by` on every kind of entry and `uploaded_by` on attachments,
 *   set to the vehicle's owner for everything already there, so that null
 *   only ever means a user who has since been deleted ("a former user").
 * - Each reminder already notified gets a delivery row for its owner and
 *   that status, so the first run after the upgrade sends nothing again.
 *
 * Explicit up/down: rolling back is refused while more than one user
 * exists, because 1.x would put several people's data in front of each of
 * them. Export the others first with bin/export-user.php.
 */
final class AddUsersAndSharing extends AbstractMigration
{
    /** Tables whose rows record who added them, and the column that does. */
    private const array AUTHORED = [
        'fuel_entries' => 'created_by',
        'odometer_readings' => 'created_by',
        'maintenance_entries' => 'created_by',
        'compliance_documents' => 'created_by',
        'expense_entries' => 'created_by',
        'tyre_changes' => 'created_by',
        'vehicle_valuations' => 'created_by',
        'attachments' => 'uploaded_by',
    ];

    public function up(): void
    {
        $this->table('users')
            ->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('disabled_at', 'datetime', ['null' => true])
            ->update();
        $this->execute('UPDATE users SET is_admin = ?', [true]);

        $this->table('vehicle_shares')
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('level', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('can_see_costs', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('notify', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'user_id'], ['unique' => true, 'name' => 'vehicle_shares_pair_uq'])
            ->addIndex(['user_id'], ['name' => 'vehicle_shares_user_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'vehicle_shares_vehicle_fk',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'vehicle_shares_user_fk',
            ])
            ->create();

        $this->table('invitations')
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('created_by', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('username', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'invitations_token_hash_uq'])
            ->addIndex(['username'], ['name' => 'invitations_username_idx'])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'invitations_created_by_fk',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'invitations_user_fk',
            ])
            ->create();

        $this->table('reminder_deliveries')
            ->addColumn('reminder_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('channels', 'json', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['reminder_id', 'user_id', 'status'], ['unique' => true, 'name' => 'reminder_deliveries_uq'])
            ->addIndex(['user_id'], ['name' => 'reminder_deliveries_user_idx'])
            ->addForeignKey('reminder_id', 'reminders', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'reminder_deliveries_reminder_fk',
            ])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'reminder_deliveries_user_fk',
            ])
            ->create();

        foreach (self::AUTHORED as $table => $column) {
            $this->table($table)
                ->addColumn($column, 'integer', ['null' => true, 'signed' => false])
                ->addForeignKey($column, 'users', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                    'constraint' => $table . '_' . $column . '_fk',
                ])
                ->update();
        }

        // Everything 1.x recorded was the owner's (a correlated subquery, portable to every engine).
        foreach (self::AUTHORED as $table => $column) {
            $this->execute(sprintf(
                'UPDATE %1$s SET %2$s = (SELECT v.user_id FROM vehicles v WHERE v.id = %1$s.vehicle_id) WHERE %2$s IS NULL',
                $table,
                $column,
            ));
        }

        // What 1.x already sent went to the owner: record it as theirs.
        $this->execute(
            'INSERT INTO reminder_deliveries (reminder_id, user_id, status, channels, sent_at, created_at)'
                . ' SELECT r.id, v.user_id, r.notified_status, r.channels_notified, r.last_notified_at,'
                . ' COALESCE(r.last_notified_at, r.updated_at)'
                . ' FROM reminders r INNER JOIN vehicles v ON v.id = r.vehicle_id'
                . ' WHERE r.notified_status IN (?, ?)',
            ['due', 'overdue'],
        );
    }

    public function down(): void
    {
        $count = $this->fetchRow('SELECT COUNT(*) AS n FROM users')['n'] ?? 0;
        $users = is_numeric($count) ? (int) $count : 0;
        if ($users > 1) {
            // Phinx opened a transaction for this migration and leaves it
            // open when down() throws; close it so nothing stays locked.
            if ($this->getAdapter()->hasTransactions()) {
                $this->getAdapter()->rollbackTransaction();
            }
            throw new RuntimeException(sprintf(
                'This install has %d users; the version before 2.0.0 has one. Export each other user first with'
                    . ' "php bin/export-user.php <username>", delete them in Settings → Users, then roll back.',
                $users,
            ));
        }

        foreach (self::AUTHORED as $table => $column) {
            $this->table($table)->dropForeignKey($column)->update();
            $this->table($table)->removeColumn($column)->update();
        }

        $this->table('reminder_deliveries')->drop()->save();
        $this->table('invitations')->drop()->save();
        $this->table('vehicle_shares')->drop()->save();

        $this->table('users')->removeColumn('disabled_at')->removeColumn('is_admin')->update();
    }
}
