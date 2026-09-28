<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A compliance document's odometer (spec.md §6 ComplianceDocument, §7.5): the
 * reading shown on it, e.g. an MOT certificate. It joins the mileage series
 * the way a service record's does: one `document` reading per document
 * (compliance_document_id), removed with it.
 *
 * `source` and `attachments.owner_type` are plain string(16) columns with no
 * check constraint or native enum on any engine, so `document`, `expense`
 * and `odometer` need no widening.
 *
 * Explicit up/down rather than change(): rolling back first turns every
 * `document` reading into a `manual` one so no mileage is lost, and SQLite
 * has no named foreign keys, so the foreign key is dropped by column (as in
 * 20260928100200).
 */
final class AddDocumentOdometer extends AbstractMigration
{
    public function up(): void
    {
        $this->table('compliance_documents')
            ->addColumn('odometer_km', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true, 'after' => 'cost'])
            ->update();

        $this->table('odometer_readings')
            ->addColumn('compliance_document_id', 'integer', ['null' => true, 'signed' => false])
            ->addIndex(['compliance_document_id'], ['unique' => true, 'name' => 'odometer_readings_compliance_document_uniq'])
            ->addForeignKey('compliance_document_id', 'compliance_documents', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'odometer_readings_compliance_document_fk',
            ])
            ->update();
    }

    public function down(): void
    {
        // Keep the mileage: a document's reading becomes an ordinary manual one.
        $this->execute(
            'UPDATE odometer_readings SET source = ?, compliance_document_id = NULL WHERE source = ?',
            ['manual', 'document'],
        );

        $readings = $this->table('odometer_readings');
        $readings->dropForeignKey('compliance_document_id')->update();
        $readings->removeIndexByName('odometer_readings_compliance_document_uniq')->update();
        $readings->removeColumn('compliance_document_id')->update();

        $this->table('compliance_documents')->removeColumn('odometer_km')->update();
    }
}
