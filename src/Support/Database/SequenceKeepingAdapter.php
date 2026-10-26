<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use Phinx\Db\Adapter\AdapterWrapper;
use Phinx\Db\Table\Table;

/**
 * Keeps a SQLite table's id counter across a change Phinx makes by rebuilding
 * the table (copy to a new table, drop the old, rename the copy): the rebuild
 * drops the table's `sqlite_sequence` row, so the counter restarts at the
 * highest id still present and an id deleted just before is given out again
 * (Phase 53.16, K63). The counter is written back after the change, never
 * lower than the one the new table already has. Other engines pass straight
 * through.
 */
final class SequenceKeepingAdapter extends AdapterWrapper
{
    public function getAdapterType(): string
    {
        return $this->getAdapter()->getAdapterType();
    }

    /**
     * @param list<object> $actions
     */
    public function executeActions(Table $table, array $actions): void
    {
        if ($this->getAdapterType() !== 'sqlite') {
            parent::executeActions($table, $actions);

            return;
        }

        $name = $table->getName();
        $before = $this->counter($name);
        parent::executeActions($table, $actions);

        if ($before !== null) {
            $this->restore($name, $before);
        }
    }

    private function counter(string $table): ?int
    {
        if ($this->fetchRow("SELECT name FROM sqlite_master WHERE name = 'sqlite_sequence'") === false) {
            return null;
        }

        $row = $this->fetchRow('SELECT seq FROM sqlite_sequence WHERE name = ' . $this->getConnection()->quote($table));

        return $row === false ? null : (int) $row['seq'];
    }

    private function restore(string $table, int $before): void
    {
        $now = $this->counter($table);

        if ($now === null) {
            $this->execute('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, $before]);
        } elseif ($now < $before) {
            $this->execute('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$before, $table]);
        }
    }
}
