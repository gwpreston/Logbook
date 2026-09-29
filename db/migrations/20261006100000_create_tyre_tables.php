<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tyres (spec.md §6 Tyre, TyreSet, TyreChange, TyreChangeLine; §7.17). Each
 * tyre is its own row; a set optionally groups them for seasonal swaps; a
 * tyre moves only through a change, one line per tyre it touches. A tyre's
 * status and position are replayed from the changes and stored so lists can
 * query them; its distance is never stored. A change has no cost column:
 * costs stay on the `tyres` service record it links to.
 *
 * A change's odometer joins the mileage series as a `tyre` reading
 * (odometer_readings.tyre_change_id), removed with it. `source` is a plain
 * string(16) column with no check constraint or native enum on any engine
 * (see 20261005100000), so `tyre` needs no widening. Codes (kind, status,
 * position, season, action, reason) are plain strings, as maintenance
 * categories are.
 *
 * Explicit up/down rather than change(): rolling back first turns every
 * `tyre` reading into a `manual` one so no mileage is lost. SQLite has no
 * named foreign keys, so the reading's foreign key is dropped by column (as
 * in 20261005100000).
 */
final class CreateTyreTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tyre_sets')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('storage_location', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id'], ['name' => 'tyre_sets_vehicle_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'tyre_sets_vehicle_fk',
            ])
            ->create();

        $this->table('tyres')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('set_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('brand', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('model', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('size', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('season', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('dot_code', 'string', ['limit' => 4, 'null' => true])
            ->addColumn('manufactured_on', 'date', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('position', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('retired_reason', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'status'], ['name' => 'tyres_vehicle_status_idx'])
            ->addIndex(['set_id'], ['name' => 'tyres_set_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'tyres_vehicle_fk',
            ])
            ->addForeignKey('set_id', 'tyre_sets', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'tyres_set_fk',
            ])
            ->create();

        $this->table('tyre_changes')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('kind', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('done_on', 'date', ['null' => false])
            ->addColumn('odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('maintenance_entry_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('note', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'done_on'], ['name' => 'tyre_changes_vehicle_done_idx'])
            ->addIndex(['maintenance_entry_id'], ['name' => 'tyre_changes_maintenance_entry_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'tyre_changes_vehicle_fk',
            ])
            ->addForeignKey('maintenance_entry_id', 'maintenance_entries', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'tyre_changes_maintenance_entry_fk',
            ])
            ->create();

        $this->table('tyre_change_lines')
            ->addColumn('change_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('tyre_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('action', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('position', 'string', ['limit' => 16, 'null' => true])
            ->addIndex(['change_id', 'tyre_id'], ['unique' => true, 'name' => 'tyre_change_lines_change_tyre_uniq'])
            ->addIndex(['tyre_id'], ['name' => 'tyre_change_lines_tyre_idx'])
            ->addForeignKey('change_id', 'tyre_changes', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'tyre_change_lines_change_fk',
            ])
            ->addForeignKey('tyre_id', 'tyres', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'tyre_change_lines_tyre_fk',
            ])
            ->create();

        $this->table('odometer_readings')
            ->addColumn('tyre_change_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['tyre_change_id'], ['unique' => true, 'name' => 'odometer_readings_tyre_change_uniq'])
            ->addForeignKey('tyre_change_id', 'tyre_changes', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_tyre_change_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // Keep the mileage: a tyre change's reading becomes an ordinary manual one.
        $this->execute(
            'UPDATE odometer_readings SET source = ?, tyre_change_id = NULL WHERE source = ?',
            ['manual', 'tyre'],
        );

        $readings = $this->table('odometer_readings');
        $readings->dropForeignKey('tyre_change_id')->update();
        $readings->removeIndexByName('odometer_readings_tyre_change_uniq')->update();
        $readings->removeColumn('tyre_change_id')->update();

        $this->table('tyre_change_lines')->drop()->save();
        $this->table('tyre_changes')->drop()->save();
        $this->table('tyres')->drop()->save();
        $this->table('tyre_sets')->drop()->save();
    }
}
