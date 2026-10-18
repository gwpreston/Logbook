<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Process;

use Logbook\Support\Process\Executable;
use Logbook\Support\Process\ProcessRunner;
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
