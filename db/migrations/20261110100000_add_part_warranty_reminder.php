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
 * one goes with it).
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
        $this->execute("DELETE FROM reminders WHERE source = 'part'");
        $this->table('parts')->removeColumn('warranty_reminder')->update();
    }
}
