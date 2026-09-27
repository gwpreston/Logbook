<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A maintenance entry's odometer joins the mileage series the same way a
 * fill-up's does: one reading per entry, removed with it.
 *
 * Explicit up/down rather than change(): SQLite has no named foreign keys,
 * so the automatic reversal (drop by constraint name) cannot run there;
 * dropping by column works on every engine.
 */
final class AddMaintenanceEntryToOdometerReadings extends AbstractMigration
{
    public function up(): void
    {
        $this->table('odometer_readings')
            ->addColumn('maintenance_entry_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['maintenance_entry_id'], ['unique' => true, 'name' => 'odometer_readings_maintenance_entry_uniq'])
            ->addForeignKey('maintenance_entry_id', 'maintenance_entries', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_maintenance_entry_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('odometer_readings');
        $table->dropForeignKey('maintenance_entry_id')->update();
        $table->removeIndexByName('odometer_readings_maintenance_entry_uniq')->update();
        $table->removeColumn('maintenance_entry_id')->update();
    }
}
