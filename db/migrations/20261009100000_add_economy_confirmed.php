<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Economy checks (spec.md §6 FuelEntry, §7.3): `economy_confirmed` holds the
 * canonical consumption (litres or kWh per 100 km) of the segment a fill-up
 * closes at the moment the owner said it *looks right*. Null for every
 * existing fill-up, so every figure is unchanged by the upgrade. Rolling
 * back drops it (the confirmations are lost; nothing else is).
 */
final class AddEconomyConfirmed extends AbstractMigration
{
    public function up(): void
    {
        $this->table('fuel_entries')
            ->addColumn('economy_confirmed', 'decimal', ['precision' => 14, 'scale' => 6, 'null' => true, 'after' => 'notes'])
            ->update();
    }

    public function down(): void
    {
        $this->table('fuel_entries')->removeColumn('economy_confirmed')->update();
    }
}
