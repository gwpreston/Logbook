<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Valuations (spec.md §6 VehicleValuation, Phase 14.1): what a vehicle was
 * said to be worth on a date. `valued_on` is a calendar date and the amount
 * is money in the vehicle's currency, like an expense.
 *
 * Their files are attachments with owner type `valuation`. Rolling back
 * removes those rows (the files stay under UPLOAD_PATH) with the table, as
 * 20261008100000 did for its owner types.
 */
final class CreateVehicleValuationsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vehicle_valuations')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('valued_on', 'date', ['null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('notes', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'valued_on'], ['name' => 'vehicle_valuations_vehicle_valued_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'vehicle_valuations_vehicle_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM attachments WHERE owner_type = ?', ['valuation']);
        $this->table('vehicle_valuations')->drop()->save();
    }
}
