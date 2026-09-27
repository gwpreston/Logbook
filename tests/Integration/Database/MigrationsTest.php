<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\JsonType;
use Logbook\Kernel;
use Logbook\Support\Config\DatabaseDriver;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Every migration must migrate AND roll back cleanly (CLAUDE.md §6).
 */
final class MigrationsTest extends AppTestCase
{
    protected function tearDown(): void
    {
        // Always leave the schema fully migrated for the rest of the suite.
        Migrator::run('migrate');
    }

    public function testFullRollbackAndReMigrate(): void
    {
        $schema = $this->connection($this->createApp())->createSchemaManager();

        self::assertTrue($schema->tablesExist(['settings']));

        Migrator::run('rollback', ['--target' => '0']);
        self::assertFalse($schema->tablesExist(['settings']), 'rollback must drop every table it created');

        Migrator::run('migrate');
        self::assertTrue($schema->tablesExist(['settings']));
    }

    public function testSettingsColumnsUsePortableTypesAndNullability(): void
    {
        if (Kernel::settings()->database->driver === DatabaseDriver::Sqlite) {
            // Phinx's SQLite adapter emits JSON_TEXT / DATETIME_TEXT, which DBAL
            // cannot introspect. SQLite is the optional quick-start engine only;
            // this contract is enforced on PostgreSQL and MySQL/MariaDB in CI.
            self::markTestSkipped('Column introspection runs on PostgreSQL and MySQL only.');
        }

        $columns = $this->columns('settings');

        self::assertInstanceOf(DateTimeType::class, $columns['created_at']->getType());
        self::assertInstanceOf(DateTimeType::class, $columns['updated_at']->getType());
        self::assertInstanceOf(JsonType::class, $columns['value']->getType());

        foreach (['scope', 'owner_id', 'name', 'created_at', 'updated_at'] as $required) {
            self::assertTrue($columns[$required]->getNotnull(), sprintf('settings.%s must be NOT NULL', $required));
        }
        self::assertFalse($columns['value']->getNotnull());
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
