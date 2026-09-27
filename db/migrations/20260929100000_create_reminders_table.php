<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reminders (spec.md §6 Reminder, §7.6): generated from maintenance
 * schedules and compliance documents, or added by hand. One row per
 * schedule / document (unique vehicle + source + source_id; manual rows have
 * no source_id), carrying the occurrence it was raised for and what has been
 * notified about it, so re-runs of the scheduled task never send twice.
 */
final class CreateRemindersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('reminders')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('source_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('occurrence', 'string', ['limit' => 64, 'null' => true])
            // Schedule category or document type code (icon, and the name of an untitled document).
            ->addColumn('category', 'string', ['limit' => 32, 'null' => true])
            // Empty for an untitled document: its type names it, in the reader's language.
            ->addColumn('title', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('notes', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('due_on', 'date', ['null' => true])
            ->addColumn('due_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('lead_time_days', 'integer', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('notified_status', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('channels_notified', 'json', ['null' => true])
            ->addColumn('last_notified_at', 'datetime', ['null' => true])
            ->addColumn('closed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'source', 'source_id'], ['unique' => true, 'name' => 'reminders_source_uq'])
            ->addIndex(['status'], ['name' => 'reminders_status_idx'])
            ->addIndex(['due_on'], ['name' => 'reminders_due_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'reminders_vehicle_fk',
            ])
            ->create();
    }
}
