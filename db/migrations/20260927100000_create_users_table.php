<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Accounts (spec.md §6 User). One owner per instance today, but nothing here
 * assumes a single row. Usernames are stored lower-case.
 */
final class CreateUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('username', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('display_name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('locale', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('timezone', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('distance_unit', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('volume_unit', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('consumption_unit', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('currency', 'string', ['limit' => 3, 'null' => false])
            ->addColumn('theme', 'string', ['limit' => 8, 'null' => false, 'default' => 'system'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['username'], ['unique' => true, 'name' => 'users_username_uq'])
            ->create();
    }
}
