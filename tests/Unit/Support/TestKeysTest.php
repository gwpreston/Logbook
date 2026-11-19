<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

/**
 * K220 (Phase 54.21, #644): the tests' signing keys are made with their own OpenSSL
 * configuration, so they work where PHP finds no `openssl.cnf` (a WinGet PHP on Windows).
 */
final class TestKeysTest extends TestCase
{
    /**
     * A child PHP with `OPENSSL_CONF` pointing nowhere stands in for such a machine: a bare
     * `openssl_pkey_new()` returns false there, and the helper must still make both kinds of key.
     */
    public function testKeysAreMadeWhenPhpFindsNoOpensslConfiguration(): void
    {
        $code = sprintf(
            'require %s; Logbook\Tests\Support\TestKeys::rsa(); Logbook\Tests\Support\TestKeys::ec(); echo "made";',
            var_export((string) realpath(dirname(__DIR__, 2) . '/Support/TestKeys.php'), true),
        );
        // The whole environment with OPENSSL_CONF laid over it: a bare array would replace it, and
        // PHP on Windows won't start without SystemRoot.
        $environment = ['OPENSSL_CONF' => DIRECTORY_SEPARATOR . 'nonexistent'] + getenv();

        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertSame('made', $output, $errors);
    }

    public function testTheConfigurationFileIsThere(): void
    {
        self::assertFileExists(TestKeys::CONFIG);
    }
}
