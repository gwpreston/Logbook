<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Schema\Column;
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
    private const array TABLES = ['settings', 'users', 'sessions', 'vehicles', 'fuel_entries', 'odometer_readings'];

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

        // Newest first: odometer readings (which reference fill-ups), fill-ups,
        // then the Phase 1 tables.
        $expected = [
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
