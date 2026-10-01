<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Incidents, damage and insurance claims (spec.md §6 Incident; §7.29,
 * Phase 27.1).
 *
 * - `incidents`: what happened to a vehicle, and the claim. The date is a
 *   calendar date, never converted through a time zone; the time is the
 *   local time of day as entered. Money is DECIMAL in the vehicle's
 *   currency. `created_by` and the driver keep the incident when the user
 *   is deleted (SET NULL), as for every other entry (20261012100000); the
 *   insurance policy likewise.
 * - `incident_id` on maintenance entries, expenses and tyre changes: the
 *   records an incident caused. Deleting the incident unlinks them.
 * - `incident_id` on odometer readings: an incident's odometer joins the
 *   mileage series as a document's does, removed with it.
 *
 * Incident photos are attachments with owner type `incident`. Rolling back
 * turns `incident` readings into manual ones (no mileage is lost), removes
 * the `incident` attachment rows (the files stay under UPLOAD_PATH, as
 * 20261010100000 did), the stalled-claim hides and incident drafts, then
 * drops the columns and the table.
 *
 * Explicit up/down rather than change(): SQLite has no named foreign keys,
 * so dropping by column is the portable reversal.
 */
final class CreateIncidents extends AbstractMigration
{
    /** The tables whose records an incident links, and their index names. */
    private const array LINKED = [
        'maintenance_entries' => 'maintenance_entries_incident_idx',
        'expense_entries' => 'expense_entries_incident_idx',
        'tyre_changes' => 'tyre_changes_incident_idx',
    ];

    public function up(): void
    {
        $money = ['precision' => 14, 'scale' => 3, 'null' => true];
        $this->table('incidents')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('occurred_on', 'date', ['null' => false])
            ->addColumn('occurred_at_time', 'string', ['limit' => 5, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('fault', 'string', ['limit' => 16, 'null' => false, 'default' => 'unknown'])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('damage_areas', 'json', ['null' => false])
            ->addColumn('severity', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('driver_user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('driver_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('other_party_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('other_party_registration', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('other_party_insurer', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('police_reference', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 8, 'null' => false, 'default' => 'open'])
            ->addColumn('closed_on', 'date', ['null' => true])
            ->addColumn('write_off_category', 'string', ['limit' => 8, 'null' => false, 'default' => 'none'])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('claim_status', 'string', ['limit' => 16, 'null' => false, 'default' => 'not_claimed'])
            ->addColumn('insurer', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('insurance_document_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('claim_number', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('excess', 'decimal', $money)
            ->addColumn('payout', 'decimal', $money)
            ->addColumn('ncd_affected', 'string', ['limit' => 8, 'null' => false, 'default' => 'unknown'])
            ->addColumn('claim_updated_on', 'date', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'occurred_on'], ['name' => 'incidents_vehicle_occurred_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'incidents_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'incidents_created_by_fk',
            ])
            ->addForeignKey('driver_user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'incidents_driver_fk',
            ])
            ->addForeignKey('insurance_document_id', 'compliance_documents', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'incidents_insurance_document_fk',
            ])
            ->create();

        foreach (self::LINKED as $table => $index) {
            $this->table($table)
                ->addColumn('incident_id', 'integer', ['null' => true, 'signed' => false])
                ->addIndex(['incident_id'], ['name' => $index])
                ->addForeignKey('incident_id', 'incidents', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'NO_ACTION',
                    'constraint' => $table . '_incident_fk',
                ])
                ->update();
        }

        $this->table('odometer_readings')
            ->addColumn('incident_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['incident_id'], ['unique' => true, 'name' => 'odometer_readings_incident_uniq'])
            ->addForeignKey('incident_id', 'incidents', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_incident_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // Keep the mileage: an incident's reading becomes an ordinary manual one.
        $this->execute(
            'UPDATE odometer_readings SET source = ?, incident_id = NULL WHERE source = ?',
            ['manual', 'incident'],
        );
        $this->execute('DELETE FROM attachments WHERE owner_type = ?', ['incident']);
        $this->execute('DELETE FROM attention_hidden WHERE kind = ?', ['stalled_claim']);
        $this->execute('DELETE FROM ai_drafts WHERE kind = ?', ['incident']);

        $readings = $this->table('odometer_readings');
        $readings->dropForeignKey('incident_id')->update();
        $readings->removeIndexByName('odometer_readings_incident_uniq')->update();
        $readings->removeColumn('incident_id')->update();

        foreach (self::LINKED as $table => $index) {
            $linked = $this->table($table);
            $linked->dropForeignKey('incident_id')->update();
            $linked->removeIndexByName($index)->update();
            $linked->removeColumn('incident_id')->update();
        }

        $this->table('incidents')->drop()->save();
    }
}
