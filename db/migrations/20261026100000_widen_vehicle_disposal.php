<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Handing back a financed car (spec.md §6 Vehicle disposal, §7.32
 * *Ending*, Phase 29.2): `vehicles.disposal` gains `returned_lender` and
 * `returned_lessor`, so the column widens from 12 to 16.
 *
 * Finance reminders (Reminder sources `finance` and `finance_end`, §7.6)
 * need no schema change: the source column holds 16 characters.
 *
 * Rolling back turns both disposals into `sold` (the version before knows
 * no other way to leave the garage with a sale price, and the sale date
 * and price stay as they are), narrows the column again, and deletes the
 * finance reminders with their deliveries, which that version can't
 * show; the next sync raises nothing in their place.
 */
final class WidenVehicleDisposal extends AbstractMigration
{
    private const array SOURCES = ['finance', 'finance_end'];

    public function up(): void
    {
        $this->table('vehicles')
            ->changeColumn('disposal', 'string', ['limit' => 16, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->execute(
            'DELETE FROM reminder_deliveries WHERE reminder_id IN (SELECT id FROM reminders WHERE source IN (?, ?))',
            self::SOURCES,
        );
        $this->execute('DELETE FROM reminders WHERE source IN (?, ?)', self::SOURCES);
        $this->execute("UPDATE vehicles SET disposal = 'sold' WHERE disposal IN ('returned_lender', 'returned_lessor')");
        $this->table('vehicles')
            ->changeColumn('disposal', 'string', ['limit' => 12, 'null' => true])
            ->update();
    }
}
