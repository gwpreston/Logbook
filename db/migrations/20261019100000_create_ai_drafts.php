<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Drafted entries from Ask Logbook (spec.md §6 AiDraft, §7.26 *Drafting
 * entries*; Phase 26.3): each card waiting for *Add*, with the validated
 * input, what the card shows and the form values for *Edit*. Applied
 * drafts keep the entry they wrote, for *Undo*.
 *
 * Not backed up. The scheduled task deletes expired drafts. Rolling back
 * drops the table; the entries drafts wrote are ordinary entries and stay.
 */
final class CreateAiDrafts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_drafts')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('thread_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('kind', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('input', 'json', ['null' => false])
            ->addColumn('card', 'json', ['null' => false])
            ->addColumn('form_values', 'json', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('discarded_at', 'datetime', ['null' => true])
            ->addColumn('applied_at', 'datetime', ['null' => true])
            ->addColumn('applied_entry_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('applied_updated_at', 'datetime', ['null' => true])
            ->addIndex(['user_id'], ['name' => 'ai_drafts_user_idx'])
            ->addIndex(['expires_at'], ['name' => 'ai_drafts_expires_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_drafts_user_fk',
            ])
            ->addForeignKey('thread_id', 'ai_threads', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_drafts_thread_fk',
            ])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_drafts_vehicle_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('ai_drafts')->drop()->save();
    }
}
