<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Scanned files waiting for the entry they will belong to (spec.md §6
 * PendingUpload, §7.27 *Reading files*; Phase 26.4): the stripped file
 * under UPLOAD_PATH/pending, what was read from it, and, once the entry is
 * saved, what its recommendations card still offers.
 *
 * Not backed up. The scheduled task deletes expired rows and their files.
 * Rolling back drops the table; files still pending under
 * UPLOAD_PATH/pending are left for the owner to remove (nothing reads
 * them any more).
 */
final class CreatePendingUploads extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pending_uploads')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('filename', 'string', ['limit' => 160, 'null' => false])
            ->addColumn('mime', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('size', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('stored_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('vehicle_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('target', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('page_count', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('result', 'json', ['null' => true])
            ->addColumn('recommendations', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(['token'], ['unique' => true, 'name' => 'pending_uploads_token_idx'])
            ->addIndex(['user_id'], ['name' => 'pending_uploads_user_idx'])
            ->addIndex(['expires_at'], ['name' => 'pending_uploads_expires_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'pending_uploads_user_fk',
            ])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'pending_uploads_vehicle_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('pending_uploads')->drop()->save();
    }
}
