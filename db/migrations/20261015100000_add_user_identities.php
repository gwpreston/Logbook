<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Single sign-on (spec.md §6 UserIdentity, User; §7.9; Phase 23.1).
 *
 * - `user_identities`: a provider account linked to a user, by issuer and
 *   subject. `(provider, issuer, subject)` is unique, so one provider
 *   account reaches at most one user.
 * - `users.password_hash` becomes nullable: a user created through single
 *   sign-on has no password until they set one.
 *
 * Rolling back is refused while any user has no password (the version
 * before has no way to let them in), naming them. It also removes the
 * break-glass sign-in links (`invitations.kind = 'login'`), which the
 * version before cannot read.
 */
final class AddUserIdentities extends AbstractMigration
{
    public function up(): void
    {
        $this->table('user_identities')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('provider', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('issuer', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider', 'issuer', 'subject'], ['unique' => true, 'name' => 'user_identities_subject_uq'])
            ->addIndex(['user_id'], ['name' => 'user_identities_user_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'user_identities_user_fk',
            ])
            ->create();

        $this->table('users')
            ->changeColumn('password_hash', 'string', ['limit' => 255, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $rows = $this->fetchAll('SELECT username FROM users WHERE password_hash IS NULL ORDER BY username');
        $names = [];
        foreach ($rows as $row) {
            $names[] = is_array($row) && is_string($row['username'] ?? null) ? $row['username'] : '?';
        }
        if ($names !== []) {
            // Phinx opened a transaction for this migration and leaves it
            // open when down() throws; close it so nothing stays locked.
            if ($this->getAdapter()->hasTransactions()) {
                $this->getAdapter()->rollbackTransaction();
            }
            throw new RuntimeException(sprintf(
                'These users have no password and could not sign in to the version before 2.3.0: %s.'
                    . ' Give each one a password (Settings → Account → Set a password, or an admin\'s reset link),'
                    . ' then roll back.',
                implode(', ', $names),
            ));
        }

        $this->execute("DELETE FROM invitations WHERE kind = 'login'");
        $this->table('user_identities')->drop()->save();
        $this->table('users')
            ->changeColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->update();
    }
}
