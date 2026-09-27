<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Insurance, pollution certificates, registration, inspections (spec.md §6
 * ComplianceDocument). Start and expiry are calendar dates; the cost is
 * decimal(14,3) and may be 0.
 */
final class CreateComplianceDocumentsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('compliance_documents')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('provider', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('reference', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('start_on', 'date', ['null' => true])
            ->addColumn('expiry_on', 'date', ['null' => true])
            ->addColumn('cost', 'decimal', ['precision' => 14, 'scale' => 3, 'null' => false])
            ->addColumn('notes', 'string', ['limit' => 1000, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'type'], ['name' => 'compliance_documents_vehicle_type_idx'])
            ->addIndex(['expiry_on'], ['name' => 'compliance_documents_expiry_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'compliance_documents_vehicle_fk',
            ])
            ->create();
    }
}
