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
     * Sessions are never backed up; `phinxlog` is the schema version.
     */
    public const array TABLES = [
        'settings',
        'users',
        'vehicles',
        'fuel_entries',
        'maintenance_schedules',
        'maintenance_entries',
        'odometer_readings',
        'compliance_documents',
        'attachments',
        'reminders',
        'expense_entries',
    ];

    /** Tables that are deliberately not backed up. */
    public const array EXCLUDED = ['sessions', 'phinxlog'];

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
