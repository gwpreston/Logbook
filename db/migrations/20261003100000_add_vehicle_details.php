<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Vehicle details (spec.md §6 Vehicle, §7.1): an optional variant / trim and
 * an optional first registration date (a calendar date, never converted
 * through a time zone). Existing vehicles get null; nothing is guessed.
 */
final class AddVehicleDetails extends AbstractMigration
{
    public function change(): void
    {
        $this->table('vehicles')
            ->addColumn('variant', 'string', ['limit' => 100, 'null' => true, 'after' => 'model'])
            ->addColumn('first_registered_on', 'date', ['null' => true, 'after' => 'year'])
            ->update();
    }
}
