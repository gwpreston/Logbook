<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 50.2 (spec.md §6 Part `warranty_reminder`, §7.6 *Part warranty
 * reminders*; #437): the tick *Remind me before it ends*, off for every
 * existing part. Reminders it raises are generated rows with source `part`.
 *
 * Rolling back drops the column and deletes the `part` reminders (they are
 * generated from the column, so nothing is lost; an owner's *done* mark on
 * one goes with it). It also deletes Ask and MCP drafts of kind
 * `modification` (new in 50.2): older code can't read that kind, and one
 * pending draft would break its owner's dashboard until it expired.
 */
final class AddPartWarrantyReminder extends AbstractMigration
{
    public function up(): void
    {
        $this->table('parts')
            ->addColumn('warranty_reminder', 'boolean', ['null' => false, 'default' => false, 'after' => 'warranty_terms'])
            ->update();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM ai_drafts WHERE kind = ?', ['modification']);
        // Their deliveries first: SQLite under Phinx has foreign keys off, so the cascade never fires.
        $this->execute("DELETE FROM reminder_deliveries WHERE reminder_id IN (SELECT id FROM reminders WHERE source = 'part')");
        $this->execute("DELETE FROM reminders WHERE source = 'part'");
        $this->table('parts')->removeColumn('warranty_reminder')->update();
    }
}
