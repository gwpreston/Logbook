<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ad-hoc expenses (spec.md §6 ExpenseEntry): parking, tolls, road tax and the
 * like. spent_on is a calendar date and an amount of 0 is valid. Fuel,
 * maintenance and document costs are not copied here; they roll up from their
 * own tables in the service layer, so nothing can be counted twice.
 */
final class CreateExpenseEntriesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('expense_entries')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('spent_on', 'date', ['null' => false])
            ->addColumn('category', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('amount', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('note', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'spent_on'], ['name' => 'expense_entries_vehicle_spent_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'expense_entries_vehicle_fk',
            ])
            ->create();
    }
}
