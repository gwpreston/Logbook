<?php

declare(strict_types=1);

namespace Logbook\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Whole-database reads and writes for backup and restore (spec.md §7.13).
 *
 * Values travel as strings (or null) so a backup restores onto any engine:
 * every engine turns a bound string into the column's type ('1' into a
 * boolean, '2026-09-27 10:00:00' into a datetime, a decimal string into a
 * decimal).
 */
final readonly class BackupRepository
{
    /**
     * Every data table, parents before children (the foreign keys), so
     * restore empties them back to front and refills them front to back.
     * Sessions and invitations are never backed up; `phinxlog` is the schema version.
     */
    public const array TABLES = [
        'settings',
        'users',
        // Phase 23.1: linked single sign-on accounts (issuer and subject).
        'user_identities',
        // API keys (Phase 18.2): hashed with SESSION_SECRET, so they work only
        // where it is the same (the restore page says so).
        'api_keys',
        'vehicles',
        'fuel_entries',
        'maintenance_schedules',
        'maintenance_entries',
        // Before the readings: a document's odometer reading refers to it.
        'compliance_documents',
        // Tyres (Phase 11.1): a change refers to its service record, and a
        // change's odometer reading to the change, so they come before the readings.
        'tyre_sets',
        'tyres',
        'tyre_changes',
        'tyre_change_lines',
        'odometer_readings',
        'attachments',
        'reminders',
        'expense_entries',
        'vehicle_valuations',
        // Phase 19: who shares which vehicle, and who has been sent which
        // reminder status (so a restore sends nothing again).
        'vehicle_shares',
        'reminder_deliveries',
        // Phase 22: trips, and each user's saved journeys and mileage rates.
        'trips',
        'saved_journeys',
        'mileage_rate_sets',
        // Phase 24: the data checks each user has hidden (Needs attention).
        'attention_hidden',
        // Phase 26.1: AI connections, their models and the task routing.
        // Never their secrets (`ai_secrets`): a restored connection asks
        // for its key again.
        'ai_connections',
        'ai_models',
        'ai_tasks',
    ];

    /**
     * Tables that are deliberately not backed up. Invitation links (Phase
     * 19) are for this install, now, like sessions. AI secrets, the usage
     * log and the per-user request lock (Phase 26.1) are never carried, nor
     * Ask Logbook's threads, progress lines and feedback counts (Phase 26.2),
     * nor its drafted entries (Phase 26.3), nor scanned files waiting for
     * their entry (Phase 26.4; their files are left out by FileStorage::all()).
     */
    public const array EXCLUDED = [
        'sessions',
        'invitations',
        'phinxlog',
        'ai_secrets',
        'ai_requests',
        'ai_busy',
        'ai_threads',
        'ai_messages',
        'ai_progress',
        'ai_feedback',
        'ai_drafts',
        'pending_uploads',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * The latest applied migration: backups restore only onto the same schema.
     */
    public function schemaVersion(): string
    {
        $version = $this->connection->createQueryBuilder()
            ->select('MAX(version)')
            ->from('phinxlog')
            ->fetchOne();

        return is_scalar($version) ? (string) $version : '0';
    }

    /**
     * @return list<string> every table in the database (lower-case)
     */
    public function tableNames(): array
    {
        $names = array_map(
            static fn (string $name): string => strtolower($name),
            $this->connection->createSchemaManager()->listTableNames(),
        );
        sort($names);

        return $names;
    }

    /**
     * @return list<string> the table's columns, in the database's order
     */
    public function columns(string $table): array
    {
        self::assertKnown($table);

        // Read from an empty result rather than the schema manager, which
        // cannot introspect Phinx's SQLite column types (spec.md §6.1).
        $result = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->where('1 = 0')
            ->executeQuery();
        $columns = [];
        for ($i = 0; $i < $result->columnCount(); $i++) {
            $columns[] = strtolower($result->getColumnName($i));
        }
        $result->free();

        return $columns;
    }

    /**
     * Every row of a table, oldest first, as column → string|null.
     *
     * @return list<array<string, string|null>>
     */
    public function rows(string $table): array
    {
        self::assertKnown($table);

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($table)
            ->orderBy('id')
            ->fetchAllAssociative();

        return array_values(array_map(static function (array $row): array {
            $values = [];
            foreach ($row as $column => $value) {
                $values[strtolower((string) $column)] = match (true) {
                    $value === null => null,
                    is_bool($value) => $value ? '1' : '0',
                    is_scalar($value) => (string) $value,
                    default => throw new UnexpectedValueException(sprintf('Unexpected value in column %s.', $column)),
                };
            }

            return $values;
        }, $rows));
    }

    /**
     * Replace every table's rows with the given ones, in one transaction.
     * Column names must already be validated against columns().
     *
     * @param array<string, list<array<string, string|null>>> $data table → rows, for every table in TABLES
     */
    public function replaceAll(array $data): void
    {
        $this->connection->transactional(function (Connection $connection) use ($data): void {
            // Links point at the replaced accounts (and would block deleting them).
            $connection->createQueryBuilder()->delete('invitations')->executeStatement();
            foreach (array_reverse(self::TABLES) as $table) {
                $connection->createQueryBuilder()->delete($table)->executeStatement();
            }
            // Every session belonged to the replaced accounts.
            $connection->createQueryBuilder()->delete('sessions')->executeStatement();

            foreach (self::TABLES as $table) {
                foreach ($data[$table] ?? [] as $row) {
                    $connection->insert($table, $row);
                }
            }

            $this->resetSequences($connection);
        });
    }

    /**
     * Rows were inserted with their ids. MySQL and SQLite move their
     * auto-increment counters past them by themselves; PostgreSQL's
     * sequences do not, so move them (the documented platform branch).
     */
    private function resetSequences(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        foreach (self::TABLES as $table) {
            // $table comes from the constant list above, never from input.
            $connection->executeQuery(
                sprintf(
                    'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(id) FROM %s), 0) + 1, false)',
                    $table,
                ),
                [$table, 'id'],
            );
        }
    }

    private static function assertKnown(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a backed-up table.', $table));
        }
    }
}
