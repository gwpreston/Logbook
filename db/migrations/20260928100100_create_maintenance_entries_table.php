<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Service history (spec.md §6 MaintenanceEntry). performed_on is a calendar
 * date; the odometer is optional (a receipt may not show it) and, when given,
 * is also written to odometer_readings. A cost of 0 is valid. schedule_id
 * marks the entry as completing a recurring schedule.
 */
final class CreateMaintenanceEntriesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('maintenance_entries')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('schedule_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('performed_on', 'date', ['null' => false])
            ->addColumn('odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('category', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 2000, 'null' => true])
            ->addColumn('cost', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('vendor', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'performed_on'], ['name' => 'maintenance_entries_vehicle_performed_idx'])
            ->addIndex(['schedule_id'], ['name' => 'maintenance_entries_schedule_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'maintenance_entries_vehicle_fk',
            ])
            ->addForeignKey('schedule_id', 'maintenance_schedules', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'maintenance_entries_schedule_fk',
            ])
            ->create();
    }
}
