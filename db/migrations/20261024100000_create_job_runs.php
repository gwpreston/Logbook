<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Each run of a background job (spec.md §6 JobRun, §7.30; Phase 28.1):
 * which job, what triggered it, who pressed *Run now*, when, how it went,
 * and its redacted output (at most 64 KB, so MEDIUMTEXT on MySQL, whose
 * TEXT stops just short of it).
 *
 * Not backed up. The scheduler's settings (`jobs.*`) are ordinary rows in
 * `settings`, so nothing else changes. Rolling back drops the table.
 */
final class CreateJobRuns extends AbstractMigration
{
    public function up(): void
    {
        $this->table('job_runs')
            ->addColumn('job', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('trigger_kind', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('user_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('started_at', 'datetime', ['null' => false])
            ->addColumn('finished_at', 'datetime', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('summary', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('output', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_MEDIUM])
            ->addIndex(['job', 'started_at'], ['name' => 'job_runs_job_started_idx'])
            ->addIndex(['started_at'], ['name' => 'job_runs_started_idx'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'job_runs_user_fk',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('job_runs')->drop()->save();
    }
}
