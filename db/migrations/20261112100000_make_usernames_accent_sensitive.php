<?php

declare(strict_types=1);

use Logbook\Support\Database\Migration;

/**
 * Phase 53.18 (spec.md §6 *User*, §7.9; #452, K44): `josé` and `jose` are
 * different usernames on every engine. PostgreSQL and SQLite already compare
 * a username exactly; MySQL and MariaDB did not (`utf8mb4_unicode_ci` ignores
 * accents), so `jose` matched `josé`. This is the one platform branch
 * (CLAUDE.md §6): on MySQL and MariaDB `users.username` and
 * `invitations.username` become `utf8mb4_bin`, and every comparison with
 * `=` is then exact. Usernames are stored lower-case, so case is unaffected.
 *
 * Before changing anything it looks for two usernames that differ only by an
 * accent and stops with their names if it finds any. The unique index made
 * that impossible among users, so none is expected.
 *
 * Rolling back restores the accent-insensitive collation, which would make
 * `jose` and `josé` collide: it stops, naming them, while both exist.
 */
final class MakeUsernamesAccentSensitive extends Migration
{
    private const array COLUMNS = ['users', 'invitations'];

    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        $pairs = $this->accentPairs();
        if ($pairs !== []) {
            throw new RuntimeException(
                'Usernames that differ only by an accent: ' . implode(', ', $pairs)
                . '. Rename or remove one of each pair, then run the upgrade again.',
            );
        }

        foreach (self::COLUMNS as $table) {
            $this->execute(sprintf(
                'ALTER TABLE %s MODIFY username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
                $table,
            ));
        }
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        $pairs = $this->accentPairs();
        if ($pairs !== []) {
            throw new RuntimeException(
                'Rolling back would make these usernames the same: ' . implode(', ', $pairs)
                . '. Rename or remove one of each pair first.',
            );
        }

        foreach (self::COLUMNS as $table) {
            $this->execute(sprintf(
                'ALTER TABLE %s MODIFY username VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
                $table,
            ));
        }
    }

    /**
     * Pairs ("josé / jose") equal under the accent-insensitive collation but
     * not byte for byte, in either table.
     *
     * @return list<string>
     */
    private function accentPairs(): array
    {
        $pairs = [];
        foreach (self::COLUMNS as $table) {
            $rows = $this->fetchAll(sprintf(
                'SELECT a.username AS first_name, b.username AS second_name FROM %1$s a JOIN %1$s b ON a.id < b.id'
                . ' AND a.username COLLATE utf8mb4_unicode_ci = b.username COLLATE utf8mb4_unicode_ci'
                . ' AND BINARY a.username <> BINARY b.username',
                $table,
            ));
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['first_name'] ?? null) && is_string($row['second_name'] ?? null)) {
                    $pairs[] = $row['first_name'] . ' / ' . $row['second_name'];
                }
            }
        }

        return $pairs;
    }
}
