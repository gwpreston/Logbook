<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * API keys (spec.md §6 ApiKey, §7.20, Phase 18.2). Like sessions, only an
 * HMAC of the token is stored. A user's keys go with the user.
 */
final class CreateApiKeysTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('api_keys')
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('scope', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'api_keys_token_hash_idx'])
            ->addIndex(['user_id'], ['name' => 'api_keys_user_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'api_keys_user_fk',
            ])
            ->create();
    }
}
