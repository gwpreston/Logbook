<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Files attached to fill-ups, maintenance entries and compliance documents
 * (spec.md §6 Attachment). owner_type + owner_id name the entry; vehicle_id
 * scopes every lookup to one vehicle and lets its files be found when the
 * vehicle is deleted. The file itself lives under UPLOAD_PATH.
 */
final class CreateAttachmentsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('attachments')
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('owner_type', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('owner_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('size', 'biginteger', ['null' => false, 'signed' => false])
            ->addColumn('stored_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('uploaded_at', 'datetime', ['null' => false])
            ->addIndex(['vehicle_id', 'owner_type', 'owner_id'], ['name' => 'attachments_owner_idx'])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'attachments_vehicle_fk',
            ])
            ->create();
    }
}
