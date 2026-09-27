<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The mileage series (spec.md §6 OdometerReading): manual readings plus one
 * reading per fill-up (fuel_entry_id, removed with its fill-up). Maintenance
 * entries add their own reference in Phase 3.
 */
final class CreateOdometerReadingsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('odometer_readings')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('reading_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('recorded_at', 'datetime', ['null' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('fuel_entry_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'recorded_at'], ['name' => 'odometer_readings_vehicle_recorded_idx'])
            ->addIndex(['fuel_entry_id'], ['unique' => true, 'name' => 'odometer_readings_fuel_entry_uniq'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_vehicle_fk',
            ])
            ->addForeignKey('fuel_entry_id', 'fuel_entries', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_fuel_entry_fk',
            ])
            ->create();
    }
}
