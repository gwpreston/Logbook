<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Process;

use Logbook\Support\Process\Executable;
use Logbook\Support\Process\ProcessResult;
use Logbook\Support\Process\ProcessRunner;
use Logbook\Support\Process\RunningProcess;
use PHPUnit\Framework\TestCase;

/**
 * Running a binary without a shell, with a timeout, and with output too
 * large for a pipe (Phase 53.1).
 */
final class ProcessRunnerTest extends TestCase
{
    public function testOutputErrorsAndTheExitCodeComeBack(): void
    {
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err"); exit(3);'], 10);

        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
        self::assertSame(3, $result->exitCode);
        self::assertFalse($result->ok());
        self::assertFalse($result->timedOut);
    }

    public function testArgumentsPassUntouchedWithNoShell(): void
    {
        $argument = "it's \"quoted\"; \$HOME `id` | cat > /tmp/x";
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', $argument], 10);

        self::assertTrue($result->ok());
        self::assertSame($argument, $result->stdout);
    }

    public function testExtraEnvironmentIsPassed(): void
    {
        $command = [PHP_BINARY, '-r', 'echo getenv("OMP_THREAD_LIMIT");'];
        $result = (new ProcessRunner())->run($command, 10, ['OMP_THREAD_LIMIT' => '1']);

        self::assertSame('1', $result->stdout);
    }

    public function testAProcessPastItsTimeIsKilled(): void
    {
        $started = microtime(true);
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'sleep(10);'], 0.5);

        self::assertTrue($result->timedOut);
        self::assertFalse($result->ok());
        self::assertLessThan(5, microtime(true) - $started);
    }

    public function testOutputLargerThanAPipeDoesNotStall(): void
    {
        $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'echo str_repeat("x", 1048576);'], 10);

        self::assertTrue($result->ok());
        self::assertSame(1048576, strlen($result->stdout));
    }

    public function testABinaryThatIsNotThereFails(): void
    {
        $result = (new ProcessRunner())->run(['/nonexistent/tesseract'], 5);

        self::assertFalse($result->ok());
    }

    public function testStartReturnsAtOnceAndWaitCollectsTheResult(): void
    {
        $runner = new ProcessRunner();
        $started = microtime(true);
        $running = $runner->start([PHP_BINARY, '-r', 'usleep(500000); echo "out"; fwrite(STDERR, "err"); exit(4);'], 10);

        self::assertInstanceOf(RunningProcess::class, $running);
        self::assertLessThan(0.4, microtime(true) - $started, 'start() did not wait');
        $result = $running->wait();

        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
        self::assertSame(4, $result->exitCode);
        self::assertGreaterThanOrEqual(0.4, microtime(true) - $started);
    }

    public function testTheTimeoutCountsFromStart(): void
    {
        $running = (new ProcessRunner())->start([PHP_BINARY, '-r', 'sleep(10);'], 0.5);
        self::assertInstanceOf(RunningProcess::class, $running);
        usleep(700_000);

        $waited = microtime(true);
        $result = $running->wait();

        self::assertTrue($result->timedOut);
        self::assertLessThan(0.4, microtime(true) - $waited, 'already past its time when wait() was called');
    }

    public function testWaitingTwiceReturnsTheSameResult(): void
    {
        $running = (new ProcessRunner())->start([PHP_BINARY, '-r', 'echo "once";'], 10);
        self::assertInstanceOf(RunningProcess::class, $running);

        $first = $running->wait();

        self::assertSame($first, $running->wait());
        self::assertSame('once', $first->stdout);
    }

    public function testAProcessNeverCollectedIsKilledWhenItsObjectIsDestroyed(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('needs the posix extension');
        }
        $pidFile = tempnam(sys_get_temp_dir(), 'logbook-pid');
        self::assertNotFalse($pidFile);
        try {
            $running = (new ProcessRunner())->start(
                [PHP_BINARY, '-r', 'file_put_contents($argv[1], getmypid()); sleep(30);', $pidFile],
                60,
            );
            self::assertInstanceOf(RunningProcess::class, $running);
            for ($i = 0; $i < 200 && (string) file_get_contents($pidFile) === ''; $i++) {
                usleep(25_000);
            }
            $pid = (int) file_get_contents($pidFile);
            self::assertGreaterThan(0, $pid, 'the child started');
            self::assertTrue(posix_kill($pid, 0), 'and is running');

            unset($running);

            self::assertFalse(posix_kill($pid, 0), 'gone once nobody can collect it');
        } finally {
            @unlink($pidFile);
        }
    }

    public function testABinaryThatCannotBeStartedIsAResultAtOnce(): void
    {
        $result = (new ProcessRunner())->start(['/nonexistent/tesseract'], 5);

        self::assertInstanceOf(ProcessResult::class, $result);
        self::assertSame(-1, $result->exitCode);
        self::assertFalse($result->ok());
    }

    public function testExecutablesAreFoundByPathOrOnPath(): void
    {
        self::assertSame(PHP_BINARY, Executable::find(PHP_BINARY));
        self::assertNull(Executable::find(''));
        self::assertNull(Executable::find('/nonexistent/tesseract'));
        self::assertNull(Executable::find('logbook-no-such-binary'));
        $sh = Executable::find('sh');
        self::assertNotNull($sh);
        self::assertStringEndsWith('/sh', $sh);
    }
}
