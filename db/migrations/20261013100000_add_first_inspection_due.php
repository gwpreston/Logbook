<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * First MOT due (spec.md §6 Vehicle, §7.1, §7.6; Phase 21.2): an optional
 * calendar date on the vehicle, never converted through a time zone.
 * Existing vehicles get null; the overview offers each one a one-time
 * prompt instead of a backfill.
 *
 * Rolling back deletes the `first_inspection` reminders (with their
 * deliveries: SQLite does not always enforce the cascade) and the prompt
 * settings, then drops the column, so 2.0 never sees a source it does not
 * know.
 */
final class AddFirstInspectionDue extends AbstractMigration
{
    private const string SOURCE = 'first_inspection';
    private const string PROMPTED = 'vehicles.first_inspection_prompted';

    public function up(): void
    {
        $this->table('vehicles')
            ->addColumn('first_inspection_due_on', 'date', ['null' => true, 'after' => 'first_registered_on'])
            ->update();
    }

    public function down(): void
    {
        $this->execute(
            'DELETE FROM reminder_deliveries WHERE reminder_id IN (SELECT id FROM reminders WHERE source = ?)',
            [self::SOURCE],
        );
        $this->execute('DELETE FROM reminders WHERE source = ?', [self::SOURCE]);
        $this->execute('DELETE FROM settings WHERE name = ?', [self::PROMPTED]);

        $this->table('vehicles')
            ->removeColumn('first_inspection_due_on')
            ->update();
    }
}
