<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fill-ups and charges (spec.md §6 FuelEntry). Volume is litres, or kWh for
 * electricity; the price per unit keeps 6 places so a price typed per gallon
 * converts back exactly; money is decimal(14,3). filled_at is a UTC instant.
 */
final class CreateFuelEntriesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('fuel_entries')
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('filled_at', 'datetime', ['null' => false])
            ->addColumn('odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('fuel', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('volume', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => false])
            ->addColumn('price_per_unit', 'decimal', ['precision' => 14, 'scale' => 6, 'null' => false])
            ->addColumn('total_cost', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('is_partial', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_missed_previous', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('station', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('notes', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'filled_at'], ['name' => 'fuel_entries_vehicle_filled_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fuel_entries_vehicle_fk',
            ])
            ->create();
    }
}
