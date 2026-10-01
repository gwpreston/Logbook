<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ask Logbook (spec.md §6 AiThread … AiFeedback, §7.26; Phase 26.2).
 *
 * - `ai_threads`: a user's conversations, kept for their retention
 *   setting from the last message.
 * - `ai_messages`: each question and answer, with the tool calls behind
 *   an answer, the grounding result and the feedback mark.
 * - `ai_progress`: which tools a running question has started, keyed by
 *   a token the page sends, so it can poll for progress lines.
 * - `ai_feedback`: counts of *Helpful* and *Not right* per month, kept
 *   when threads are deleted.
 *
 * None of them is backed up. Rolling back drops them and the
 * `ai.ask_retention_days` setting, which the version before does not read.
 */
final class CreateAskTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_threads')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('title', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['user_id', 'updated_at'], ['name' => 'ai_threads_user_idx'])
            ->addIndex(['updated_at'], ['name' => 'ai_threads_updated_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_threads_user_fk',
            ])
            ->create();

        $this->table('ai_messages')
            ->addColumn('thread_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('role', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('content', 'text', ['null' => false])
            ->addColumn('tool_calls', 'json', ['null' => true])
            ->addColumn('grounding', 'json', ['null' => true])
            ->addColumn('connection_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('model', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('error_code', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('feedback', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['thread_id'], ['name' => 'ai_messages_thread_idx'])
            ->addForeignKey('thread_id', 'ai_threads', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_messages_thread_fk',
            ])
            ->create();

        $this->table('ai_progress')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('token', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('tools', 'json', ['null' => true])
            ->addColumn('done', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('thread_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['token'], ['unique' => true, 'name' => 'ai_progress_token_uq'])
            ->addIndex(['updated_at'], ['name' => 'ai_progress_updated_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_progress_user_fk',
            ])
            ->create();

        $this->table('ai_feedback')
            ->addColumn('month', 'string', ['limit' => 7, 'null' => false])
            ->addColumn('mark', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('total', 'integer', ['null' => false, 'signed' => false, 'default' => 0])
            ->addIndex(['month', 'mark'], ['unique' => true, 'name' => 'ai_feedback_month_uq'])
            ->create();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM settings WHERE name = 'ai.ask_retention_days'");
        $this->table('ai_feedback')->drop()->save();
        $this->table('ai_progress')->drop()->save();
        $this->table('ai_messages')->drop()->save();
        $this->table('ai_threads')->drop()->save();
    }
}
