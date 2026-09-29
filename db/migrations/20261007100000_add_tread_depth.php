<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tread depth (spec.md §6 User, TyreChangeLine; §7.17): a depth unit
 * preference and the depth measured on any tyre change line, in millimetres
 * (`decimal(6,3)`, so a value typed in 32nds of an inch round-trips). Owners
 * who buy fuel by the US gallon get 32nds; everyone else millimetres.
 *
 * The new change kind `check`, line action `measure` and reminder source
 * `tyre` are plain string(16) codes (see 20261006100000), so nothing widens.
 *
 * Explicit up/down rather than change(): an older version cannot read a
 * `check` change or a `tyre` reminder, so rolling back first turns the
 * readings of `check` changes into `manual` ones (no mileage is lost), then
 * deletes those changes (their `measure` lines go with them), tyre
 * reminders and the tyre thresholds setting.
 */
final class AddTreadDepth extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('depth_unit', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'mm',
                'after' => 'consumption_unit',
            ])
            ->update();
        $this->execute('UPDATE users SET depth_unit = ? WHERE volume_unit = ?', ['in32', 'gal_us']);

        $this->table('tyre_change_lines')
            ->addColumn('tread_mm', 'decimal', ['precision' => 6, 'scale' => 3, 'null' => true, 'after' => 'position'])
            ->update();
    }

    public function down(): void
    {
        $this->execute(
            'UPDATE odometer_readings SET source = ?, tyre_change_id = NULL'
                . ' WHERE tyre_change_id IN (SELECT id FROM tyre_changes WHERE kind = ?)',
            ['manual', 'check'],
        );
        $this->execute('DELETE FROM tyre_change_lines WHERE action = ?', ['measure']);
        $this->execute('DELETE FROM tyre_changes WHERE kind = ?', ['check']);
        $this->execute('DELETE FROM reminders WHERE source = ?', ['tyre']);
        $this->execute('DELETE FROM settings WHERE name = ?', ['tyres.thresholds']);

        $this->table('tyre_change_lines')->removeColumn('tread_mm')->update();
        $this->table('users')->removeColumn('depth_unit')->update();
    }
}
