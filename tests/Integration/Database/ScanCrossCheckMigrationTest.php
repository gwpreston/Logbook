<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use Doctrine\DBAL\ParameterType;
use Logbook\Tests\Support\AiTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * Phase 53.8 (spec.md §7.27 *Send text, not images*; #532, K107): rolling
 * back Phase 53.3's migration while a connection sends text only names it
 * and warns that pictures go to it once upgraded again; the rollback still
 * runs. With none on, it says nothing.
 */
final class ScanCrossCheckMigrationTest extends AiTestCase
{
    /** Phase 51's part warranty reminder, the migration before 53.3's. */
    private const string BEFORE = '20261110100000';

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testRollingBackWarnsOfEachConnectionThatSendsTextOnly(): void
    {
        $app = $this->aiApp();
        $this->resetDatabase($app);
        $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');
        $this->cloud($app);
        $this->connection($app)->executeStatement(
            "UPDATE ai_connections SET send_text = ? WHERE name = 'OpenAI'",
            [true],
            [ParameterType::BOOLEAN],
        );

        $output = Migrator::run('rollback', ['--target' => self::BEFORE]);

        self::assertStringContainsString('Send text, not images is dropped from OpenAI.', $output);
        self::assertStringNotContainsString('Ollama', $output);
        self::assertStringContainsString('AddScanCrossCheck: reverted', $output, 'the rollback still ran');
    }

    public function testRollingBackWithNoneOnSaysNothing(): void
    {
        $app = $this->aiApp();
        $this->resetDatabase($app);
        $this->cloud($app);

        self::assertStringNotContainsString('Send text, not images', Migrator::run('rollback', ['--target' => self::BEFORE]));
    }
}
