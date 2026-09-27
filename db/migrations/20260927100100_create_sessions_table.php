<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Server-side sessions (spec.md §6 Session). The id is an HMAC of the cookie
 * token, never the token itself. Rows of a deleted user go with it.
 */
final class CreateSessionsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('sessions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'string', ['limit' => 64, 'null' => false])
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('data', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_activity_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['name' => 'sessions_user_idx'])
            ->addIndex(['last_activity_at'], ['name' => 'sessions_last_activity_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'sessions_user_fk',
            ])
            ->create();
    }
}
