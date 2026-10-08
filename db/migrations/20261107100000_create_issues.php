<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The issues log (spec.md §6 Issue, IssueFix and IssueUpdate; §7.37,
 * Phase 40.1, decided 2026-10-08, #307–#318).
 *
 * - `issues`: a fault the owner noticed. Dates are calendar dates, never
 *   converted through a time zone. `created_by` keeps the issue when the
 *   user is deleted (SET NULL), as for every other entry.
 * - `issue_fixes`: the service records that fixed an issue; either side's
 *   deletion removes the link.
 * - `issue_updates`: the timeline: notes and automatic status changes;
 *   `reason` says why an automatic one was written, so it is shown in the
 *   reader's language.
 * - `issue_id` and `issue_update_id` on odometer readings: an issue's or an
 *   update's odometer joins the mileage series (sources `issue` and
 *   `issue_update`, #307), removed with it.
 *
 * Issue files are attachments with owner type `issue`; a look-again point
 * raises a reminder with source `issue` (#311). Rolling back turns issue
 * readings into manual ones (no mileage is lost), removes the `issue`
 * attachment rows (the files stay under UPLOAD_PATH) and `issue`
 * reminders, then drops the columns and the tables.
 */
final class CreateIssues extends AbstractMigration
{
    public function up(): void
    {
        $km = ['precision' => 12, 'scale' => 3, 'null' => true];
        $this->table('issues')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('noticed_on', 'date', ['null' => false])
            ->addColumn('odometer_km', 'decimal', $km)
            ->addColumn('title', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('category', 'string', ['limit' => 16, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 8, 'null' => false, 'default' => 'open'])
            ->addColumn('affects_safety', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('look_again_on', 'date', ['null' => true])
            ->addColumn('look_again_km', 'decimal', $km)
            ->addColumn('fixed_on', 'date', ['null' => true])
            ->addColumn('status_before_fix', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 16, 'null' => false, 'default' => 'manual'])
            ->addColumn('source_ref', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'status'], ['name' => 'issues_vehicle_status_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'issues_vehicle_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'issues_created_by_fk',
            ])
            ->create();

        $this->table('issue_fixes', ['id' => false, 'primary_key' => ['issue_id', 'maintenance_entry_id']])
            ->addColumn('issue_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('maintenance_entry_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['maintenance_entry_id'], ['name' => 'issue_fixes_record_idx'])
            ->addForeignKey('issue_id', 'issues', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'issue_fixes_issue_fk',
            ])
            ->addForeignKey('maintenance_entry_id', 'maintenance_entries', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'issue_fixes_record_fk',
            ])
            ->create();

        $this->table('issue_updates')
            ->addColumn('issue_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('noted_on', 'date', ['null' => false])
            ->addColumn('odometer_km', 'decimal', $km)
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('status_from', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('status_to', 'string', ['limit' => 8, 'null' => true])
            ->addColumn('reason', 'string', ['limit' => 24, 'null' => true])
            ->addColumn('created_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['issue_id', 'noted_on'], ['name' => 'issue_updates_issue_idx'])
            ->addForeignKey('issue_id', 'issues', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'issue_updates_issue_fk',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'issue_updates_created_by_fk',
            ])
            ->create();

        $this->table('odometer_readings')
            ->addColumn('issue_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('issue_update_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['issue_id'], ['unique' => true, 'name' => 'odometer_readings_issue_uniq'])
            ->addIndex(['issue_update_id'], ['unique' => true, 'name' => 'odometer_readings_issue_update_uniq'])
            ->addForeignKey('issue_id', 'issues', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_issue_fk',
            ])
            ->addForeignKey('issue_update_id', 'issue_updates', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_issue_update_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // Keep the mileage: an issue's reading becomes an ordinary manual one.
        $this->execute(
            'UPDATE odometer_readings SET source = ?, issue_id = NULL, issue_update_id = NULL WHERE source IN (?, ?)',
            ['manual', 'issue', 'issue_update'],
        );
        $this->execute('DELETE FROM attachments WHERE owner_type = ?', ['issue']);
        $this->execute('DELETE FROM reminders WHERE source = ?', ['issue']);

        $readings = $this->table('odometer_readings');
        $readings->dropForeignKey('issue_update_id')->update();
        $readings->dropForeignKey('issue_id')->update();
        $readings->removeIndexByName('odometer_readings_issue_update_uniq')->update();
        $readings->removeIndexByName('odometer_readings_issue_uniq')->update();
        $readings->removeColumn('issue_update_id')->update();
        $readings->removeColumn('issue_id')->update();

        $this->table('issue_updates')->drop()->save();
        $this->table('issue_fixes')->drop()->save();
        $this->table('issues')->drop()->save();
    }
}
