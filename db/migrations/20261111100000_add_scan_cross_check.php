<?php

declare(strict_types=1);

use Logbook\Support\Database\Migration;
use Symfony\Component\Console\Formatter\OutputFormatter;

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
 * can't keep that promise, so it names each such connection and warns
 * before it goes on (Phase 53.8, #532, K107), and the release notes say
 * to roll back only with it off.
 */
final class AddScanCrossCheck extends Migration
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
        $names = [];
        foreach ($this->fetchAll('SELECT name, send_text FROM ai_connections') as $row) {
            // Each engine returns a boolean its own way.
            if (is_array($row) && in_array($row['send_text'] ?? null, [true, 1, '1', 't', 'true'], true)) {
                // Escaped: a name is free text, and `<options=…>` in it would stop the rollback (K133).
                $names[] = OutputFormatter::escape(is_string($row['name'] ?? null) ? $row['name'] : '');
            }
        }
        if ($names !== []) {
            $this->getOutput()?->writeln(sprintf(
                '<comment>Warning: Send text, not images is dropped from %s. Upgraded again, photos go to %s'
                . ' as pictures until it is turned back on.</comment>',
                implode(', ', $names),
                count($names) === 1 ? 'it' : 'them',
            ));
        }
        $this->table('ai_requests')
            ->removeColumn('scan_unchecked')
            ->removeColumn('scan_flagged')
            ->removeColumn('scan_found')
            ->update();
        $this->table('ai_connections')->removeColumn('send_text')->update();
    }
}
