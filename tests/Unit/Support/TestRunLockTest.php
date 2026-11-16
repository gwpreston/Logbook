<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Tests\Support\TestRunLock;
use PHPUnit\Framework\TestCase;

/**
 * K197 (#619): a second test run from the same checkout waits on the lock,
 * saying so once, and goes on as soon as the first run lets go. A child PHP
 * process stands in for the second run; this test holds the lock directly
 * (not through TestRunLock, whose handle lives as long as the process).
 */
final class TestRunLockTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/logbook-test-run-lock-' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testASecondRunWaitsSayingSoAndGoesOnWhenTheFirstEnds(): void
    {
        $held = fopen($this->path, 'c');
        self::assertIsResource($held);
        self::assertTrue(flock($held, LOCK_EX));

        $child = $this->second();
        [$process, $stdout, $stderr] = $child;
        // The child runs PHP from cold, so give it time to reach the lock and print.
        $waiting = $this->readUntil($stderr, TestRunLock::WAITING, 10.0);
        self::assertSame(TestRunLock::WAITING, $waiting, 'the second run says it is waiting');
        usleep(200_000);
        self::assertTrue(proc_get_status($process)['running'], 'and does not go on while the lock is held');

        flock($held, LOCK_UN);
        fclose($held);

        $out = $this->readUntil($stdout, "locked\n", 10.0);
        self::assertSame("locked\n", $out, 'it goes on once the first run ends');
        self::assertSame('', (string) stream_get_contents($stderr), 'the message is printed once');
        fclose($stdout);
        fclose($stderr);
        self::assertSame(0, proc_close($process));
    }

    public function testAFreeLockIsTakenWithoutAMessage(): void
    {
        [$process, $stdout, $stderr] = $this->second();
        stream_set_blocking($stdout, true);
        stream_set_blocking($stderr, true);
        self::assertSame("locked\n", stream_get_contents($stdout));
        self::assertSame('', stream_get_contents($stderr));
        fclose($stdout);
        fclose($stderr);
        self::assertSame(0, proc_close($process));
    }

    /**
     * K207: CONTRIBUTING.md tells a contributor what the waiting line means,
     * and that a run inside Docker Desktop on macOS doesn't share the lock
     * (K201).
     */
    public function testContributingExplainsTheWait(): void
    {
        $contributing = (string) file_get_contents(dirname(__DIR__, 3) . '/CONTRIBUTING.md');

        self::assertStringContainsString(rtrim(TestRunLock::WAITING), $contributing);
        self::assertStringContainsString('var/testing.lock', $contributing);
        self::assertStringContainsString('Docker Desktop on macOS', $contributing);
    }

    /**
     * A child process that takes the lock through TestRunLock and says so.
     *
     * @return array{0: resource, 1: resource, 2: resource} process, stdout, stderr
     */
    private function second(): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; Logbook\Tests\Support\TestRunLock::acquire(%s);'
                . ' echo Logbook\Tests\Support\TestRunLock::isHeld() ? "locked\n" : "not held\n";',
            var_export($autoload, true),
            var_export($this->path, true),
        );
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * What $stream says until it has said $expected or $seconds pass.
     *
     * @param resource $stream non-blocking
     */
    private function readUntil($stream, string $expected, float $seconds): string
    {
        $read = '';
        $until = microtime(true) + $seconds;
        while (strlen($read) < strlen($expected) && microtime(true) < $until) {
            $read .= (string) fread($stream, 8192);
            usleep(20_000);
        }

        return $read;
    }
}
