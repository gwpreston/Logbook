<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The garage (spec.md §6 Vehicle). Capacity is litres (kWh for electric
 * vehicles); money is decimal(14,3); purchase/sale dates are calendar dates.
 */
final class CreateVehiclesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('vehicles')
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('nickname', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('make', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('model', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('year', 'integer', ['null' => true])
            ->addColumn('registration', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('vin', 'string', ['limit' => 17, 'null' => true])
            ->addColumn('fuel_type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('capacity', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true])
            ->addColumn('currency', 'string', ['limit' => 3, 'null' => true])
            ->addColumn('photo_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('photo_mime', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('purchase_date', 'date', ['null' => true])
            ->addColumn('purchase_price', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => true])
            ->addColumn('sale_date', 'date', ['null' => true])
            ->addColumn('sale_price', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'active'])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'status'], ['name' => 'vehicles_user_status_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'vehicles_user_fk',
            ])
            ->create();
    }
}
