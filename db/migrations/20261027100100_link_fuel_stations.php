<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Existing station texts become stations (spec.md §7.33 *Upgrading*,
 * Phase 30.1; decided 2026-10-02, open questions #131 and #133).
 *
 * The fill-ups are read in batches by id (a constant LIMIT, the same SQL
 * on every engine). Each non-empty `station` text is
 * normalised in PHP (trimmed, whitespace collapsed, case-folded), never in
 * SQL, so every engine and collation groups alike (MySQL's default
 * collations fold case, PostgreSQL's don't). One station is created per
 * normalised name across the whole install, named with its most common
 * spelling (ties: the one used first). Its creator is the owner of the
 * vehicle with the earliest fill-up under that name, and its country that
 * owner's locale region, or none. Home charging (grade `home`) is never a
 * station and is left alone.
 *
 * The normalisation is Logbook\Domain\Station\StationName::normalise(),
 * repeated here so the migration never depends on application code
 * (a test pins the two together).
 *
 * Rolling back unlinks every fill-up and deletes the stations, favourites
 * and places; the text column was never changed.
 */
final class LinkFuelStations extends AbstractMigration
{
    private const int BATCH = 500;

    public function up(): void
    {
        /**
         * @var array<string, array{
         *     spellings: array<string, array{count: int, first: string}>,
         *     earliest: string,
         *     owner: ?int,
         *     locale: ?string,
         *     ids: list<int>,
         * }> $names
         */
        $names = [];
        $after = 0;
        do {
            $rows = $this->rows(
                'SELECT f.id, f.station, f.grade, f.filled_at, v.user_id, u.locale FROM fuel_entries f'
                . ' INNER JOIN vehicles v ON v.id = f.vehicle_id'
                . ' LEFT JOIN users u ON u.id = v.user_id'
                . ' WHERE f.id > ? AND f.station IS NOT NULL ORDER BY f.id LIMIT ' . self::BATCH,
                [$after],
            );
            foreach ($rows as $row) {
                $after = self::int($row['id'] ?? null);
                $text = is_string($row['station']) ? $row['station'] : '';
                $key = self::normalise($text);
                if ($key === '' || $row['grade'] === 'home') {
                    continue;
                }
                $spelling = self::tidy($text);
                $at = substr(self::text($row['filled_at'] ?? null), 0, 19);
                $name = $names[$key] ?? ['spellings' => [], 'earliest' => $at, 'owner' => null, 'locale' => null, 'ids' => []];
                $name['spellings'][$spelling] ??= ['count' => 0, 'first' => $at];
                $name['spellings'][$spelling]['count']++;
                if ($at < $name['spellings'][$spelling]['first']) {
                    $name['spellings'][$spelling]['first'] = $at;
                }
                if ($name['ids'] === [] || $at < $name['earliest']) {
                    $name['earliest'] = $at;
                    $name['owner'] = ($row['user_id'] ?? null) === null ? null : self::int($row['user_id']);
                    $name['locale'] = is_string($row['locale']) ? $row['locale'] : null;
                }
                $name['ids'][] = self::int($row['id'] ?? null);
                $names[$key] = $name;
            }
        } while (count($rows) === self::BATCH);

        if ($names === []) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $byName = [];
        foreach ($names as $key => $name) {
            $spellings = $name['spellings'];
            uksort($spellings, static fn (string $a, string $b): int => [$spellings[$b]['count'], $spellings[$a]['first'], $a]
                <=> [$spellings[$a]['count'], $spellings[$b]['first'], $b]);
            $label = (string) array_key_first($spellings);
            $byName[$label] = $key;
            $this->table('stations')->insert([
                'name' => $label,
                'country' => self::region($name['locale']),
                'created_by' => $name['owner'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveData();
        }

        // Every label is distinct (different normalised names), so the new
        // rows are found by name without relying on lastInsertId().
        $stations = $this->rows('SELECT id, name FROM stations WHERE merged_into IS NULL', []);
        foreach ($stations as $station) {
            $key = $byName[self::text($station['name'] ?? null)] ?? null;
            if ($key === null) {
                continue;
            }
            foreach (array_chunk($names[$key]['ids'], self::BATCH) as $ids) {
                $marks = implode(', ', array_fill(0, count($ids), '?'));
                $this->execute(
                    'UPDATE fuel_entries SET station_id = ? WHERE id IN (' . $marks . ')',
                    [self::int($station['id'] ?? null), ...$ids],
                );
            }
        }
    }

    public function down(): void
    {
        $this->execute('UPDATE fuel_entries SET station_id = NULL WHERE station_id IS NOT NULL');
        $this->execute('DELETE FROM station_favourites');
        $this->execute('DELETE FROM places');
        $this->execute('UPDATE stations SET merged_into = NULL WHERE merged_into IS NOT NULL');
        $this->execute('DELETE FROM stations');
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->query($sql, $params);

        if (!$statement instanceof PDOStatement) {
            return [];
        }
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = array_combine(array_map(strval(...), array_keys($row)), array_values($row));
            }
        }

        return $rows;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function tidy(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(self::tidy($text), 'UTF-8');
    }

    private static function region(?string $locale): ?string
    {
        $region = $locale === null ? '' : (string) Locale::getRegion($locale);

        return $region === '' ? null : strtoupper($region);
    }
}
