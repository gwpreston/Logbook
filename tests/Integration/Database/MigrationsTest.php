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
        'tyre_sets',
        'tyres',
        'tyre_changes',
        'tyre_change_lines',
        'vehicle_valuations',
        'api_keys',
        'vehicle_shares',
        'invitations',
        'reminder_deliveries',
        'trips',
        'saved_journeys',
        'mileage_rate_sets',
        'user_identities',
    ];

    /** Tables with a Phase 19 created_by column. */
    private const array AUTHORED = [
        'fuel_entries',
        'odometer_readings',
        'maintenance_entries',
        'compliance_documents',
        'expense_entries',
        'tyre_changes',
        'vehicle_valuations',
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

        // Newest first: the Phase 23.1 identities, the Phase 22 trip tables,
        // the Phase 21.2 first MOT date, the Phase 19 users and sharing, the Phase 18.2 API
        // keys, the Phase 14.1 valuations table, the Phase 13 economy confirmation, the Phase 12
        // purchase and sale paperwork (no schema change), the Phase 11.2
        // tread depth, the Phase 11.1 tyre tables, the Phase 10 document odometer, the Phase 9.2
        // plug-in hybrid data migration (no schema change), the Phase 9.1
        // vehicle details, the Phase 8 grade
        // columns, the Phase 7 accent column, the Phase 5, 4 and 3 tables,
        // then the column Phase 3 added to odometer_readings, then Phase 2
        // and Phase 1 tables.
        self::assertTrue($schema->tablesExist(['user_identities']));
        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['user_identities']), 'rollback must drop the identities');

        self::assertTrue($schema->tablesExist(['trips', 'saved_journeys', 'mileage_rate_sets']));
        Migrator::run('rollback');
        foreach (['trips', 'saved_journeys', 'mileage_rate_sets'] as $table) {
            self::assertFalse($schema->tablesExist([$table]), sprintf('rollback must drop %s', $table));
        }

        self::assertTrue($this->hasColumn('vehicles', 'first_inspection_due_on'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('vehicles', 'first_inspection_due_on'), 'rollback must drop the first MOT date');
        self::assertTrue($this->hasColumn('vehicles', 'first_registered_on'), 'and keep the first registration');

        self::assertTrue($schema->tablesExist(['vehicle_shares', 'invitations', 'reminder_deliveries']));
        self::assertTrue($this->hasColumn('fuel_entries', 'created_by'));
        Migrator::run('rollback');
        foreach (['vehicle_shares', 'invitations', 'reminder_deliveries'] as $table) {
            self::assertFalse($schema->tablesExist([$table]), sprintf('rollback must drop %s', $table));
        }
        foreach (self::AUTHORED as $table) {
            self::assertFalse($this->hasColumn($table, 'created_by'), sprintf('rollback must drop %s.created_by', $table));
        }
        self::assertFalse($this->hasColumn('attachments', 'uploaded_by'), 'and attachments.uploaded_by');
        self::assertFalse($this->hasColumn('users', 'is_admin'), 'and the admin flag');
        self::assertFalse($this->hasColumn('users', 'disabled_at'), 'and the disabled time');

        self::assertTrue($schema->tablesExist(['api_keys']));
        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['api_keys']), 'rollback must drop the API keys');
        self::assertTrue($schema->tablesExist(['users', 'vehicle_valuations']), 'and keep the users and the rest');
        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['vehicle_valuations']), 'rollback must drop the valuations');
        self::assertTrue($this->hasColumn('fuel_entries', 'economy_confirmed'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('fuel_entries', 'economy_confirmed'), 'rollback must drop the economy confirmation');
        Migrator::run('rollback');
        self::assertTrue($this->hasColumn('users', 'depth_unit'), 'the paperwork changes no column');
        self::assertTrue($this->hasColumn('tyre_change_lines', 'tread_mm'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('users', 'depth_unit'), 'rollback must drop the depth unit');
        self::assertFalse($this->hasColumn('tyre_change_lines', 'tread_mm'), 'and the tread depth');
        self::assertTrue($this->hasColumn('users', 'consumption_unit'), 'and keep the other units');

        self::assertTrue($schema->tablesExist(['tyre_sets', 'tyres', 'tyre_changes', 'tyre_change_lines']));
        self::assertTrue($this->hasColumn('odometer_readings', 'tyre_change_id'));
        Migrator::run('rollback');
        foreach (['tyre_change_lines', 'tyre_changes', 'tyres', 'tyre_sets'] as $table) {
            self::assertFalse($schema->tablesExist([$table]), sprintf('rollback must drop %s', $table));
        }
        self::assertFalse($this->hasColumn('odometer_readings', 'tyre_change_id'), 'and the reading link');
        self::assertTrue($this->hasColumn('odometer_readings', 'compliance_document_id'), 'and keep the other links');

        self::assertTrue($this->hasColumn('compliance_documents', 'odometer_km'));
        self::assertTrue($this->hasColumn('odometer_readings', 'compliance_document_id'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('compliance_documents', 'odometer_km'), 'rollback must drop the document odometer');
        self::assertFalse($this->hasColumn('odometer_readings', 'compliance_document_id'), 'and the reading link');
        self::assertTrue($this->hasColumn('odometer_readings', 'maintenance_entry_id'), 'and keep the other links');

        Migrator::run('rollback');
        self::assertTrue($this->hasColumn('vehicles', 'fuel_type'), 'the plug-in hybrid split changes data only');

        self::assertTrue($this->hasColumn('vehicles', 'variant'));
        self::assertTrue($this->hasColumn('vehicles', 'first_registered_on'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('vehicles', 'variant'), 'rollback must drop the variant column');
        self::assertFalse($this->hasColumn('vehicles', 'first_registered_on'), 'and the first registration column');
        self::assertTrue($this->hasColumn('vehicles', 'model'), 'and keep the rest of the vehicles table');

        self::assertTrue($this->hasColumn('fuel_entries', 'grade'));
        self::assertTrue($this->hasColumn('vehicles', 'default_grade'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('fuel_entries', 'grade'), 'rollback must drop the grade column');
        self::assertFalse($this->hasColumn('vehicles', 'default_grade'), 'and the default grade column');
        self::assertTrue($this->hasColumn('fuel_entries', 'fuel'), 'and keep the rest of the fill-ups table');
        self::assertTrue($this->hasColumn('vehicles', 'fuel_type'), 'and of the vehicles table');

        self::assertTrue($this->hasColumn('users', 'accent'));
        Migrator::run('rollback');
        self::assertFalse($this->hasColumn('users', 'accent'), 'rollback must drop the accent column');
        self::assertTrue($this->hasColumn('users', 'theme'), 'and keep the rest of the users table');
        self::assertTrue($schema->tablesExist(['users', 'sessions', 'expense_entries']));

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
        self::assertInstanceOf(DecimalType::class, $documents['odometer_km']->getType());
        self::assertSame(12, $documents['odometer_km']->getPrecision());
        self::assertSame(3, $documents['odometer_km']->getScale());
        self::assertFalse($documents['odometer_km']->getNotnull(), 'a document may show no odometer');
        self::assertFalse($this->columns('odometer_readings')['compliance_document_id']->getNotnull());

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

    public function testUserAndSharingColumns(): void
    {
        $users = $this->columnsOrSkip('users');
        self::assertInstanceOf(BooleanType::class, $users['is_admin']->getType());
        self::assertTrue($users['is_admin']->getNotnull());
        self::assertInstanceOf(DateTimeType::class, $users['disabled_at']->getType());
        self::assertFalse($users['disabled_at']->getNotnull());

        $shares = $this->columnsOrSkip('vehicle_shares');
        foreach (['can_see_costs', 'notify'] as $flag) {
            self::assertInstanceOf(BooleanType::class, $shares[$flag]->getType(), $flag);
        }
        foreach (['vehicle_id', 'user_id', 'level', 'can_see_costs', 'notify', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($shares[$required]->getNotnull(), sprintf('vehicle_shares.%s must be NOT NULL', $required));
        }

        $invitations = $this->columnsOrSkip('invitations');
        foreach (['expires_at', 'used_at', 'revoked_at', 'created_at'] as $instant) {
            self::assertInstanceOf(DateTimeType::class, $invitations[$instant]->getType(), $instant);
        }
        self::assertFalse($invitations['user_id']->getNotnull(), 'an invite is for nobody yet');

        $deliveries = $this->columnsOrSkip('reminder_deliveries');
        self::assertInstanceOf(JsonType::class, $deliveries['channels']->getType());
        self::assertFalse($deliveries['sent_at']->getNotnull(), 'null while a run holds the claim');

        foreach (self::AUTHORED as $table) {
            $createdBy = $this->columnsOrSkip($table)['created_by'];
            self::assertFalse($createdBy->getNotnull(), sprintf('%s.created_by is optional', $table));
        }
        self::assertFalse($this->columnsOrSkip('attachments')['uploaded_by']->getNotnull());
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
        self::assertFalse($columns['grade']->getNotnull(), 'a grade is optional: null means not recorded');
        self::assertSame(20, $columns['grade']->getLength());
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
        $columns = ['username', 'display_name', 'locale', 'timezone'];
        $columns = [...$columns, 'distance_unit', 'volume_unit', 'consumption_unit', 'currency', 'theme'];
        foreach ($columns as $required) {
            self::assertTrue($users[$required]->getNotnull(), sprintf('users.%s must be NOT NULL', $required));
        }
        // Phase 23.1: a user created through single sign-on has no password.
        self::assertFalse($users['password_hash']->getNotnull(), 'users.password_hash is nullable');
        self::assertSame(255, $users['password_hash']->getLength(), 'and keeps its length');
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
        self::assertFalse($columns['default_grade']->getNotnull());
        self::assertInstanceOf(DateType::class, $columns['first_registered_on']->getType(), 'a calendar date, not an instant');
        self::assertFalse($columns['first_registered_on']->getNotnull());
        self::assertInstanceOf(DateType::class, $columns['first_inspection_due_on']->getType(), 'a calendar date too');
        self::assertFalse($columns['first_inspection_due_on']->getNotnull());
        self::assertSame(100, $columns['variant']->getLength());
        self::assertFalse($columns['variant']->getNotnull());

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
