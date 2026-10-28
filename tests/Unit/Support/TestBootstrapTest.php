<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * K148 (Phase 54): the test bootstrap refuses to run outside `APP_ENV=testing`,
 * so phpunit without its configuration can't delete or migrate the app's own
 * database.
 */
final class TestBootstrapTest extends TestCase
{
    public function testItRefusesOutsideTheTestingEnvironmentAndLeavesTheDatabaseAlone(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'logbook-k148-');
        self::assertIsString($database);
        file_put_contents($database, 'not touched');

        $command = sprintf(
            'APP_ENV=development DB_DRIVER=sqlite DB_NAME=%s %s %s 2>&1',
            escapeshellarg($database),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bootstrap.php'),
        );
        exec($command, $output, $status);

        try {
            self::assertSame(1, $status, implode("\n", $output));
            self::assertStringContainsString('APP_ENV is not "testing"', implode("\n", $output));
            self::assertFileExists($database);
            self::assertSame('not touched', file_get_contents($database));
        } finally {
            @unlink($database);
        }
    }
}
