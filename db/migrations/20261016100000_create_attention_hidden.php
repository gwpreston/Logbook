<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Needs attention (spec.md §6 AttentionHidden, §7.24; Phase 24).
 *
 * - `attention_hidden`: a data check a user has hidden, keyed by the
 *   check's kind and subject (a reading, or the vehicle) with a
 *   fingerprint of what was judged. The check stays hidden only while the
 *   fingerprint still matches. `(user_id, kind, subject_id)` is unique.
 *
 * The list itself is derived on every read and never stored. Rolling back
 * drops the table and the `attention.thresholds` user settings, which the
 * version before does not read.
 */
final class CreateAttentionHidden extends AbstractMigration
{
    public function up(): void
    {
        $this->table('attention_hidden')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('vehicle_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('kind', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('subject_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('fingerprint', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('hidden_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'kind', 'subject_id'], ['unique' => true, 'name' => 'attention_hidden_subject_uq'])
            ->addIndex(['vehicle_id'], ['name' => 'attention_hidden_vehicle_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'attention_hidden_user_fk',
            ])
            ->addForeignKey('vehicle_id', 'vehicles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'attention_hidden_vehicle_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->execute('DELETE FROM settings WHERE name = ?', ['attention.thresholds']);
        $this->table('attention_hidden')->drop()->save();
    }
}
