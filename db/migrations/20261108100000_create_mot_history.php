<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * MOT history from DVSA (spec.md §6 MotTest, MotDefect, MotHistorySecret;
 * §7.38, Phase 41, decided 2026-10-08, #320–#334).
 *
 * - `mot_tests`: each fetched test, unique per vehicle by its number (or,
 *   when DVSA gives none, its source and completed time, #334).
 * - `mot_defects`: each test's defects in DVSA's order; `issue_id` is the
 *   issue made from it or updated by it, unlinked when that issue goes.
 * - `mot_history_secrets`: the provider's credentials, sealed or
 *   `env:NAME`, never backed up.
 * - Vehicles: when the owner confirmed fetching, the last fetch, the
 *   recall state and DVSA's first MOT due date.
 * - `mot_test_id` on odometer readings: a test's odometer joins the
 *   mileage series (source `mot`), removed with the test.
 *
 * Rolling back deletes `mot` readings (they are DVSA's, not the owner's),
 * then drops the link, the columns and the tables. Issues and documents
 * made from tests stay, as the owner's own entries.
 */
final class CreateMotHistory extends AbstractMigration
{
    public function up(): void
    {
        $this->table('mot_tests')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('test_number', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('completed_at', 'datetime', ['null' => false])
            ->addColumn('result', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('expiry_on', 'date', ['null' => true])
            ->addColumn('odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('odometer_unit', 'string', ['limit' => 2, 'null' => true])
            ->addColumn('odometer_state', 'string', ['limit' => 12, 'null' => false])
            ->addColumn('registration_at_test', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('data_source', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('reviewed_at', 'datetime', ['null' => true])
            ->addColumn('fetched_at', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'test_number'], ['unique' => true, 'name' => 'mot_tests_number_uniq'])
            ->addIndex(['vehicle_id', 'completed_at'], ['name' => 'mot_tests_vehicle_completed_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'mot_tests_vehicle_fk',
            ])
            ->create();

        $this->table('mot_defects')
            ->addColumn('mot_test_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('position', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('text', 'text', ['null' => false])
            ->addColumn('dangerous', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('issue_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('dismissed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['mot_test_id', 'position'], ['unique' => true, 'name' => 'mot_defects_position_uniq'])
            ->addIndex(['issue_id'], ['name' => 'mot_defects_issue_idx'])
            ->addForeignKey('mot_test_id', 'mot_tests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'mot_defects_test_fk',
            ])
            ->addForeignKey('issue_id', 'issues', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'mot_defects_issue_fk',
            ])
            ->create();

        $this->table('mot_history_secrets')
            ->addColumn('provider', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('slot', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['provider', 'slot'], ['unique' => true, 'name' => 'mot_history_secrets_uq'])
            ->create();

        $this->table('vehicles')
            ->addColumn('mot_history_enabled_at', 'datetime', ['null' => true])
            ->addColumn('mot_history_fetched_at', 'datetime', ['null' => true])
            ->addColumn('mot_recall_state', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('mot_first_due_on', 'date', ['null' => true])
            ->update();

        $this->table('odometer_readings')
            ->addColumn('mot_test_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['mot_test_id'], ['unique' => true, 'name' => 'odometer_readings_mot_test_uniq'])
            ->addForeignKey('mot_test_id', 'mot_tests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_mot_test_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // DVSA's readings are not the owner's: they go with the tests.
        $this->execute('DELETE FROM odometer_readings WHERE source = ?', ['mot']);

        $readings = $this->table('odometer_readings');
        $readings->dropForeignKey('mot_test_id')->update();
        $readings->removeIndexByName('odometer_readings_mot_test_uniq')->update();
        $readings->removeColumn('mot_test_id')->update();

        $vehicles = $this->table('vehicles');
        foreach (['mot_first_due_on', 'mot_recall_state', 'mot_history_fetched_at', 'mot_history_enabled_at'] as $column) {
            $vehicles->removeColumn($column)->update();
        }

        $this->table('mot_history_secrets')->drop()->save();
        $this->table('mot_defects')->drop()->save();
        $this->table('mot_tests')->drop()->save();
    }
}
