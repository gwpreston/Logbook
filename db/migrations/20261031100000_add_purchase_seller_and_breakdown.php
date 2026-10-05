<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 33.3 (spec.md §6 Vehicle, OdometerReading, Incident; #182, #185):
 * who the vehicle was bought from (`vehicles.purchase_seller`), the
 * `purchase` odometer reading source (the vehicle form's *Mileage when
 * bought*) and the `breakdown` incident type.
 *
 * Reading sources and incident types are plain strings checked in PHP, so
 * only the column is added; the migration still moves the schema version,
 * so a backup holding the new values is never restored into an older
 * version. Rolling back turns every `purchase` reading into a `manual` one
 * (no mileage is lost, as for `document`) and every `breakdown` incident
 * into `other`, then drops the column.
 *
 * Explicit up/down rather than change(): rows change on the way back.
 */
final class AddPurchaseSellerAndBreakdown extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vehicles')
            ->addColumn('purchase_seller', 'string', ['limit' => 100, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->execute('UPDATE odometer_readings SET source = ? WHERE source = ?', ['manual', 'purchase']);
        $this->execute('UPDATE incidents SET type = ? WHERE type = ?', ['other', 'breakdown']);

        $this->table('vehicles')->removeColumn('purchase_seller')->update();
    }
}
