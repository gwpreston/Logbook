<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Total loss and reading claim letters (spec.md §6 Vehicle, Incident,
 * PendingUpload; §7.29, Phase 27.2).
 *
 * - `vehicles.disposal` (`sold` | `written_off`, null for a vehicle
 *   archived without a reason, as every vehicle archived before this) and
 *   `vehicles.disposal_incident_id`, the total-loss incident; deleting the
 *   incident leaves the vehicle written off (SET NULL).
 * - `incidents.repair_estimate`: what a repair may cost, never counted.
 * - `pending_uploads.incident_id`: the incident a scan was started for;
 *   deleting the incident deletes the pending row (CASCADE), as deleting
 *   the vehicle does.
 *
 * Rolling back drops the columns: archived vehicles stay archived, and
 * pending scans started for an incident lose their target.
 *
 * Explicit up/down rather than change(): SQLite has no named foreign keys,
 * so dropping by column is the portable reversal.
 */
final class AddTotalLossAndClaimScans extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vehicles')
            ->addColumn('disposal', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('disposal_incident_id', 'integer', ['null' => true, 'signed' => false])
            ->addForeignKey('disposal_incident_id', 'incidents', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'vehicles_disposal_incident_fk',
            ])
            ->update();

        $this->table('incidents')
            ->addColumn('repair_estimate', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => true])
            ->update();

        $this->table('pending_uploads')
            ->addColumn('incident_id', 'integer', ['null' => true, 'signed' => false])
            ->addForeignKey('incident_id', 'incidents', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'pending_uploads_incident_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->execute('UPDATE pending_uploads SET target = NULL WHERE target = ?', ['incident']);

        $pending = $this->table('pending_uploads');
        $pending->dropForeignKey('incident_id')->update();
        $pending->removeColumn('incident_id')->update();

        $this->table('incidents')->removeColumn('repair_estimate')->update();

        $vehicles = $this->table('vehicles');
        $vehicles->dropForeignKey('disposal_incident_id')->update();
        $vehicles->removeColumn('disposal_incident_id')->update();
        $vehicles->removeColumn('disposal')->update();
    }
}
