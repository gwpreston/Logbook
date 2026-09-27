<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Recurring maintenance (spec.md §6 MaintenanceSchedule): "every 10,000 km or
 * 12 months". baseline_done_* is what the owner typed as "last done" before
 * logging it; last_done_* and next_due_* are computed (from the latest entry
 * that completes the schedule, else the baseline) and stored so reminders can
 * query them. Distances in km; days are calendar dates.
 */
final class CreateMaintenanceSchedulesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('maintenance_schedules')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('category', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('interval_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('interval_months', 'integer', ['null' => true])
            ->addColumn('baseline_done_on', 'date', ['null' => true])
            ->addColumn('baseline_done_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('last_done_on', 'date', ['null' => true])
            ->addColumn('last_done_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('next_due_on', 'date', ['null' => true])
            ->addColumn('next_due_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id'], ['name' => 'maintenance_schedules_vehicle_idx'])
            ->addIndex(['next_due_on'], ['name' => 'maintenance_schedules_next_due_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'maintenance_schedules_vehicle_fk',
            ])
            ->create();
    }
}
