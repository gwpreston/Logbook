<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * AI insights (spec.md §6 AiInsightSet, §7.26 *AI insights*; Phase 33.4):
 * one row per user, the day's observations the model found with the *Ask*
 * tools, cached for that day.
 *
 * - `day`: the user's local date it was made for (YYYY-MM-DD);
 * - `insights`: each title, body, cited tool runs and unmatched figures;
 * - `tool_calls`: the tool runs behind them, as `ai_messages` keeps them;
 * - who answered, and an error code when it failed.
 *
 * Never backed up: it is made again the next day. Rolling back drops it.
 */
final class CreateAiInsights extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_insights')
            ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('day', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('insights', 'json', ['null' => true])
            ->addColumn('tool_calls', 'json', ['null' => true])
            ->addColumn('connection_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('location', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('model', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('error_code', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'ai_insights_user_uq'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'ai_insights_user_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('ai_insights')->drop()->save();
    }
}
