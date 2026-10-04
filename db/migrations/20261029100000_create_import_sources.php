<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where imported rows came from (spec.md §6 ImportSource, §7.13 *Importing
 * from another app*, Phase 31): the app and the row's own id in its export
 * (Fuelio's `guid`), per target vehicle, so a newer export adds only new
 * rows even after an imported entry is edited or deleted.
 *
 * entity_id has no foreign key: it points at a fill-up, maintenance record,
 * expense, station or schedule, and the origin stays known after the entry
 * is gone.
 *
 * Explicit up/down rather than change(): SQLite has no named foreign keys,
 * so dropping the table is the portable reversal.
 */
final class CreateImportSources extends AbstractMigration
{
    public function up(): void
    {
        $this->table('import_sources')
            ->addColumn('app', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('source_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('entity_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('entity_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('imported_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('imported_at', 'datetime', ['null' => false])
            ->addIndex(['app', 'source_id', 'vehicle_id'], ['unique' => true, 'name' => 'import_sources_source_uq'])
            ->addIndex(['vehicle_id', 'entity_type'], ['name' => 'import_sources_vehicle_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'import_sources_vehicle_fk',
            ])
            ->addForeignKey('imported_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'import_sources_imported_by_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('import_sources')->drop()->save();
    }
}
