<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\TextType;
use Logbook\Kernel;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Every migration must migrate AND roll back cleanly (CLAUDE.md §6).
 */
final class MigrationsTest extends AppTestCase
{
    private const array TABLES = [
        'settings',
        'users',
        'sessions',
        'vehicles',
        'fuel_entries',
        'odometer_readings',
        'maintenance_schedules',
        'maintenance_entries',
        'compliance_documents',
        'attachments',
        'reminders',
        'expense_entries',
    ];

    protected function tearDown(): void
    {
        // Always leave the schema fully migrated for the rest of the suite.
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testFullRollbackAndReMigrate(): void
    {
        $schema = $this->connection($this->createApp())->createSchemaManager();

        self::assertTrue($schema->tablesExist(self::TABLES));

        Migrator::run('rollback', ['--target' => '0']);
        foreach (self::TABLES as $table) {
            self::assertFalse($schema->tablesExist([$table]), sprintf('rollback must drop %s', $table));
        }

        Migrator::run('migrate');
        self::assertTrue($schema->tablesExist(self::TABLES));
    }

    public function testStepwiseRollbackOneMigrationAtATime(): void
    {
        $schema = $this->connection($this->createApp())->createSchemaManager();

        // Newest first: the Phase 5, 4 and 3 tables, then the column Phase 3
        // added to odometer_readings, then Phase 2 and Phase 1 tables.
        $expected = [
            ['expense_entries', ['reminders', 'vehicles']],
            ['reminders', ['attachments', 'vehicles', 'settings']],
            ['attachments', ['compliance_documents', 'vehicles']],
            ['compliance_documents', ['maintenance_entries', 'vehicles']],
        ];
        foreach ($expected as [$dropped, $kept]) {
            Migrator::run('rollback');
            self::assertFalse($schema->tablesExist([$dropped]), sprintf('rollback must drop %s', $dropped));
            self::assertTrue($schema->tablesExist($kept), sprintf('rolling back %s must keep the rest', $dropped));
        }

        self::assertTrue($this->hasColumn('odometer_readings', 'maintenance_entry_id'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('odometer_readings', 'maintenance_entry_id'), 'rollback must drop the column');
        self::assertTrue($this->hasColumn('odometer_readings', 'fuel_entry_id'), 'and keep the rest of the table');
        self::assertTrue($schema->tablesExist(['maintenance_entries', 'odometer_readings']));

        $expected = [
            ['maintenance_entries', ['maintenance_schedules', 'odometer_readings']],
            ['maintenance_schedules', ['odometer_readings', 'fuel_entries']],
            ['odometer_readings', ['fuel_entries', 'vehicles']],
            ['fuel_entries', ['vehicles']],
            ['vehicles', ['users', 'sessions']],
            ['sessions', ['users']],
        ];
        foreach ($expected as [$dropped, $kept]) {
            Migrator::run('rollback');
            self::assertFalse($schema->tablesExist([$dropped]), sprintf('rollback must drop %s', $dropped));
            self::assertTrue($schema->tablesExist($kept), sprintf('rolling back %s must keep the rest', $dropped));
        }
    }

    public function testMaintenanceColumnsKeepPrecisionAndUseCalendarDates(): void
    {
        $entries = $this->columnsOrSkip('maintenance_entries');
        self::assertInstanceOf(DateType::class, $entries['performed_on']->getType());
        self::assertInstanceOf(DecimalType::class, $entries['cost']->getType());
        self::assertSame(14, $entries['cost']->getPrecision());
        self::assertSame(3, $entries['cost']->getScale());
        self::assertTrue($entries['cost']->getNotnull(), 'cost is 0, never null');
        self::assertInstanceOf(DecimalType::class, $entries['odometer_km']->getType());
        self::assertSame(3, $entries['odometer_km']->getScale());
        foreach (['odometer_km', 'schedule_id', 'vendor', 'description'] as $optional) {
            self::assertFalse($entries[$optional]->getNotnull(), sprintf('maintenance_entries.%s is optional', $optional));
        }
        foreach (['vehicle_id', 'performed_on', 'category', 'title', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($entries[$required]->getNotnull(), sprintf('maintenance_entries.%s must be NOT NULL', $required));
        }

        $schedules = $this->columns('maintenance_schedules');
        foreach (['baseline_done_on', 'last_done_on', 'next_due_on'] as $date) {
            self::assertInstanceOf(DateType::class, $schedules[$date]->getType(), $date);
            self::assertFalse($schedules[$date]->getNotnull(), $date);
        }
        foreach (['interval_km', 'baseline_done_km', 'last_done_km', 'next_due_km'] as $km) {
            self::assertInstanceOf(DecimalType::class, $schedules[$km]->getType(), $km);
            self::assertSame(3, $schedules[$km]->getScale(), $km);
        }

        $odometer = $this->columns('odometer_readings');
        self::assertFalse($odometer['maintenance_entry_id']->getNotnull());
    }

    public function testComplianceAndAttachmentColumns(): void
    {
        $documents = $this->columnsOrSkip('compliance_documents');
        self::assertInstanceOf(DateType::class, $documents['start_on']->getType());
        self::assertInstanceOf(DateType::class, $documents['expiry_on']->getType());
        self::assertInstanceOf(DecimalType::class, $documents['cost']->getType());
        self::assertSame(3, $documents['cost']->getScale());
        foreach (['vehicle_id', 'type', 'cost', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($documents[$required]->getNotnull(), sprintf('compliance_documents.%s must be NOT NULL', $required));
        }

        $attachments = $this->columns('attachments');
        self::assertInstanceOf(BigIntType::class, $attachments['size']->getType());
        self::assertInstanceOf(DateTimeType::class, $attachments['uploaded_at']->getType());
        foreach (['vehicle_id', 'owner_type', 'owner_id', 'filename', 'mime', 'size', 'stored_path', 'uploaded_at'] as $name) {
            self::assertTrue($attachments[$name]->getNotnull(), sprintf('attachments.%s must be NOT NULL', $name));
        }
    }

    public function testReminderColumns(): void
    {
        $reminders = $this->columnsOrSkip('reminders');

        self::assertInstanceOf(DateType::class, $reminders['due_on']->getType());
        self::assertFalse($reminders['due_on']->getNotnull(), 'a distance-only schedule may have no date');
        self::assertInstanceOf(DecimalType::class, $reminders['due_km']->getType());
        self::assertSame(3, $reminders['due_km']->getScale());
        self::assertInstanceOf(JsonType::class, $reminders['channels_notified']->getType());
        foreach (['last_notified_at', 'closed_at', 'created_at', 'updated_at'] as $instant) {
            self::assertInstanceOf(DateTimeType::class, $reminders[$instant]->getType(), $instant);
        }
        foreach (['vehicle_id', 'source', 'title', 'lead_time_days', 'status', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($reminders[$required]->getNotnull(), sprintf('reminders.%s must be NOT NULL', $required));
        }
        $optionals = ['source_id', 'occurrence', 'category', 'notes', 'notified_status', 'channels_notified', 'last_notified_at'];
        foreach ($optionals as $optional) {
            self::assertFalse($reminders[$optional]->getNotnull(), sprintf('reminders.%s is optional', $optional));
        }
    }

    public function testExpenseColumns(): void
    {
        $expenses = $this->columnsOrSkip('expense_entries');

        self::assertInstanceOf(DateType::class, $expenses['spent_on']->getType(), 'a calendar date');
        self::assertInstanceOf(DecimalType::class, $expenses['amount']->getType());
        self::assertSame(14, $expenses['amount']->getPrecision());
        self::assertSame(3, $expenses['amount']->getScale());
        foreach (['vehicle_id', 'spent_on', 'category', 'amount', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($expenses[$required]->getNotnull(), sprintf('expense_entries.%s must be NOT NULL', $required));
        }
        self::assertFalse($expenses['note']->getNotnull());
        self::assertInstanceOf(DateTimeType::class, $expenses['created_at']->getType());
    }

    /**
     * Whether a column exists, by querying it (works on SQLite too, where
     * DBAL cannot introspect Phinx's column types).
     */
    private function hasColumn(string $table, string $column): bool
    {
        $query = $this->connection($this->createApp())->createQueryBuilder()
            ->select($column)
            ->from($table)
            ->where('1 = 0');

        try {
            $query->executeQuery();

            return true;
        } catch (DbalException) {
            return false;
        }
    }

    public function testFuelEntryColumnsKeepPrecisionAndUseUtcInstants(): void
    {
        $columns = $this->columnsOrSkip('fuel_entries');

        $decimals = ['odometer_km' => [12, 3], 'volume' => [12, 3], 'price_per_unit' => [14, 6], 'total_cost' => [14, 3]];
        foreach ($decimals as $name => [$precision, $scale]) {
            self::assertInstanceOf(DecimalType::class, $columns[$name]->getType(), $name);
            self::assertSame($precision, $columns[$name]->getPrecision(), $name);
            self::assertSame($scale, $columns[$name]->getScale(), $name);
        }
        self::assertInstanceOf(BooleanType::class, $columns['is_partial']->getType());
        self::assertInstanceOf(BooleanType::class, $columns['is_missed_previous']->getType());
        self::assertInstanceOf(DateTimeType::class, $columns['filled_at']->getType());

        $required = ['vehicle_id', 'filled_at', 'odometer_km', 'fuel', 'volume', 'price_per_unit', 'total_cost'];
        foreach ([...$required, 'is_partial', 'is_missed_previous', 'created_at', 'updated_at'] as $name) {
            self::assertTrue($columns[$name]->getNotnull(), sprintf('fuel_entries.%s must be NOT NULL', $name));
        }
        self::assertFalse($columns['station']->getNotnull());
        self::assertFalse($columns['notes']->getNotnull());
    }

    public function testOdometerReadingColumns(): void
    {
        $columns = $this->columnsOrSkip('odometer_readings');

        self::assertInstanceOf(DecimalType::class, $columns['reading_km']->getType());
        self::assertSame(3, $columns['reading_km']->getScale());
        self::assertInstanceOf(DateTimeType::class, $columns['recorded_at']->getType());
        foreach (['vehicle_id', 'reading_km', 'recorded_at', 'source', 'created_at', 'updated_at'] as $name) {
            self::assertTrue($columns[$name]->getNotnull(), sprintf('odometer_readings.%s must be NOT NULL', $name));
        }
        self::assertFalse($columns['fuel_entry_id']->getNotnull());
        self::assertFalse($columns['note']->getNotnull());
    }

    public function testSettingsColumnsUsePortableTypesAndNullability(): void
    {
        $columns = $this->columnsOrSkip('settings');

        self::assertInstanceOf(DateTimeType::class, $columns['created_at']->getType());
        self::assertInstanceOf(DateTimeType::class, $columns['updated_at']->getType());
        self::assertInstanceOf(JsonType::class, $columns['value']->getType());

        foreach (['scope', 'owner_id', 'name', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($columns[$required]->getNotnull(), sprintf('settings.%s must be NOT NULL', $required));
        }
        self::assertFalse($columns['value']->getNotnull());
    }

    public function testUserAndSessionColumns(): void
    {
        $users = $this->columnsOrSkip('users');
        $columns = ['username', 'password_hash', 'display_name', 'locale', 'timezone'];
        $columns = [...$columns, 'distance_unit', 'volume_unit', 'consumption_unit', 'currency', 'theme'];
        foreach ($columns as $required) {
            self::assertTrue($users[$required]->getNotnull(), sprintf('users.%s must be NOT NULL', $required));
        }
        self::assertInstanceOf(DateTimeType::class, $users['created_at']->getType());

        $sessions = $this->columns('sessions');
        self::assertInstanceOf(TextType::class, $sessions['data']->getType());
        self::assertFalse($sessions['user_id']->getNotnull());
        self::assertInstanceOf(DateTimeType::class, $sessions['last_activity_at']->getType());
    }

    public function testVehicleMoneyIsDecimalAndDatesAreCalendarDates(): void
    {
        $columns = $this->columnsOrSkip('vehicles');

        foreach (['purchase_price', 'sale_price'] as $money) {
            self::assertInstanceOf(DecimalType::class, $columns[$money]->getType(), $money);
            self::assertSame(14, $columns[$money]->getPrecision());
            self::assertSame(3, $columns[$money]->getScale());
            self::assertFalse($columns[$money]->getNotnull());
        }
        self::assertInstanceOf(DecimalType::class, $columns['capacity']->getType());
        self::assertSame(3, $columns['capacity']->getScale());
        self::assertInstanceOf(DateType::class, $columns['purchase_date']->getType());
        self::assertInstanceOf(DateType::class, $columns['sale_date']->getType());
        self::assertInstanceOf(DateTimeType::class, $columns['archived_at']->getType());

        foreach (['user_id', 'type', 'make', 'model', 'fuel_type', 'status', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($columns[$required]->getNotnull(), sprintf('vehicles.%s must be NOT NULL', $required));
        }
    }

    /**
     * @return array<string, Column>
     */
    private function columnsOrSkip(string $table): array
    {
        if (Kernel::settings()->database->driver === DatabaseDriver::Sqlite) {
            // Phinx's SQLite adapter emits JSON_TEXT / DATETIME_TEXT, which DBAL
            // cannot introspect. SQLite is the optional quick-start engine only;
            // this contract is enforced on PostgreSQL and MySQL/MariaDB in CI.
            self::markTestSkipped('Column introspection runs on PostgreSQL and MySQL only.');
        }

        return $this->columns($table);
    }

    /**
     * @return array<string, Column>
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->connection($this->createApp())->createSchemaManager()->listTableColumns($table) as $column) {
            $columns[strtolower($column->getName())] = $column;
        }

        return $columns;
    }
}
