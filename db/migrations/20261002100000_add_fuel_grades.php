<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Fuel grades (spec.md §6 FuelEntry and Vehicle, §7.3): an optional grade
 * code on each fill-up and an optional default grade per vehicle. Existing
 * rows get null ("not recorded"); nothing is guessed.
 */
final class AddFuelGrades extends AbstractMigration
{
    public function change(): void
    {
        $this->table('fuel_entries')
            ->addColumn('grade', 'string', ['limit' => 20, 'null' => true, 'after' => 'fuel'])
            ->update();

        $this->table('vehicles')
            ->addColumn('default_grade', 'string', ['limit' => 20, 'null' => true, 'after' => 'fuel_type'])
            ->update();
    }
}
