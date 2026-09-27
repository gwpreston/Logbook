<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Schema\Column;
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
    private const array TABLES = ['settings', 'users', 'sessions', 'vehicles'];

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

    public function testStepwiseRollbackOfPhaseOneTables(): void
    {
        $schema = $this->connection($this->createApp())->createSchemaManager();

        // Undo the newest migration only (vehicles), then the rest one at a time.
        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['vehicles']));
        self::assertTrue($schema->tablesExist(['users', 'sessions']));

        Migrator::run('rollback');
        self::assertFalse($schema->tablesExist(['sessions']));
        self::assertTrue($schema->tablesExist(['users']));
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
