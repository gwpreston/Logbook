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

        // An argument array and an environment, no shell: a POSIX `VAR=x command` line doesn't run
        // on Windows (Phase 54.21, K221). The whole environment with the three laid over it, since a
        // bare array would replace it and PHP on Windows won't start without SystemRoot.
        $environment = ['APP_ENV' => 'development', 'DB_DRIVER' => 'sqlite', 'DB_NAME' => $database] + getenv();
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bootstrap.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        try {
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('APP_ENV is not "testing"', $output);
            self::assertFileExists($database);
            self::assertSame('not touched', file_get_contents($database));
        } finally {
            @unlink($database);
        }
    }
}
