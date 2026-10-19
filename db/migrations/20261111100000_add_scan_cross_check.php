<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 53.3 (spec.md §6 AiConnection `send_text`, AiRequest `scan_*`;
 * §7.27 *Where it goes*, *The cross-check*; #499, #508, #509):
 *
 * - `ai_connections.send_text`: *Send text, not images*, off for every
 *   existing connection.
 * - `ai_requests.scan_found`, `scan_flagged`, `scan_unchecked`: a vision
 *   scan's cross-check counts, empty on every other row.
 *
 * Rolling back drops the columns. Older code then sends images on a
 * connection that had *Send text, not images* on: the down migration
 * can't keep that promise, so the release notes say to roll back only
 * with it off.
 */
final class AddScanCrossCheck extends AbstractMigration
{
    public function up(): void
    {
        $this->table('ai_connections')
            ->addColumn('send_text', 'boolean', ['null' => false, 'default' => false, 'after' => 'enabled'])
            ->update();
        $this->table('ai_requests')
            ->addColumn('scan_found', 'integer', ['null' => true, 'after' => 'content'])
            ->addColumn('scan_flagged', 'integer', ['null' => true, 'after' => 'scan_found'])
            ->addColumn('scan_unchecked', 'integer', ['null' => true, 'after' => 'scan_flagged'])
            ->update();
    }

    public function down(): void
    {
        $this->table('ai_requests')
            ->removeColumn('scan_unchecked')
            ->removeColumn('scan_flagged')
            ->removeColumn('scan_found')
            ->update();
        $this->table('ai_connections')->removeColumn('send_text')->update();
    }
}
