<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Baseline migration: key/value settings (feature toggles, defaults, and
 * later per-user preferences such as dashboard layout).
 *
 * Portable column conventions used from here on (spec.md §6.1):
 *  - always state 'null' explicitly: Phinx 0.16 makes columns nullable by default
 *  - timestamps: `datetime`, always written as UTC by the app
 *  - structured values: `json`
 *  - money / quantities: `decimal` (never float)
 *  - booleans: `boolean`
 */
final class CreateSettingsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('settings')
            ->addColumn('scope', 'string', ['limit' => 16, 'null' => false, 'default' => 'global'])
            ->addColumn('owner_id', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('value', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['scope', 'owner_id', 'name'], ['unique' => true, 'name' => 'settings_scope_owner_name_uq'])
            ->create();
    }
}
