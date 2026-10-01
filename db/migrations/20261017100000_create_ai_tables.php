<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI foundation (spec.md §6 AiConnection … AiBusy, §7.25; Phase 26.1).
 *
 * - `ai_connections`: where a model provider runs and how to reach it.
 *   Secrets are never stored here.
 * - `ai_secrets`: each connection's API key and header values, encrypted
 *   with a key from SESSION_SECRET (or an `env:` reference). Not backed up.
 * - `ai_models`: the models listed or typed per connection, with their
 *   capabilities and the last *Test*.
 * - `ai_tasks`: which model does which job; one row per task.
 * - `ai_requests`: the usage log, without content by default. Not backed up.
 * - `ai_busy`: one row per user while a request runs. Not backed up.
 *
 * Rolling back drops the tables and the `ai.use` and `ai.this_host`
 * settings, which the version before does not read.
 */
final class CreateAiTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_connections')
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('adapter', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('base_url', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('location', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('header_names', 'json', ['null' => true])
            ->addColumn('timeout_seconds', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('verify_tls', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('ca_bundle', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('max_request_mb', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('monthly_token_cap', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('enabled', 'boolean', ['null' => false, 'default' => true])
            // Unsigned to match Phinx's default primary keys on MySQL.
            ->addColumn('acknowledged_by', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('acknowledged_at', 'datetime', ['null' => true])
            ->addColumn('acknowledged_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addForeignKey('acknowledged_by', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_connections_ack_user_fk',
            ])
            ->create();

        $this->table('ai_secrets')
            ->addColumn('connection_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('slot', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('value', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['connection_id', 'slot'], ['unique' => true, 'name' => 'ai_secrets_slot_uq'])
            ->addForeignKey('connection_id', 'ai_connections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_secrets_connection_fk',
            ])
            ->create();

        $this->table('ai_models')
            ->addColumn('connection_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('listed', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('added', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('tools', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('images', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('json', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('json_mode', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('tested_at', 'datetime', ['null' => true])
            ->addColumn('test_results', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['connection_id', 'name'], ['unique' => true, 'name' => 'ai_models_name_uq'])
            ->addForeignKey('connection_id', 'ai_connections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_models_connection_fk',
            ])
            ->create();

        $this->table('ai_tasks')
            ->addColumn('task', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('model_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('temperature', 'decimal', ['precision' => 3, 'scale' => 2, 'null' => true])
            ->addColumn('max_output_tokens', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['task'], ['unique' => true, 'name' => 'ai_tasks_task_uq'])
            ->addIndex(['model_id'], ['name' => 'ai_tasks_model_idx'])
            ->addForeignKey('model_id', 'ai_models', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_tasks_model_fk',
            ])
            ->create();

        $this->table('ai_requests')
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('task', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('connection_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('model', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('tokens_in', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('tokens_out', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('duration_ms', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('outcome', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('error_code', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('content', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['created_at'], ['name' => 'ai_requests_created_idx'])
            ->addIndex(['connection_id', 'created_at'], ['name' => 'ai_requests_connection_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_requests_user_fk',
            ])
            ->addForeignKey('connection_id', 'ai_connections', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_requests_connection_fk',
            ])
            ->create();

        $this->table('ai_busy')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'ai_busy_user_uq'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_busy_user_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM settings WHERE name IN ('ai.use', 'ai.this_host')");
        $this->table('ai_busy')->drop()->save();
        $this->table('ai_requests')->drop()->save();
        $this->table('ai_tasks')->drop()->save();
        $this->table('ai_models')->drop()->save();
        $this->table('ai_secrets')->drop()->save();
        $this->table('ai_connections')->drop()->save();
    }
}
