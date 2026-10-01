<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A header without trusted proxies stops the app at start (spec.md §7.9,
 * §9), in the web entry point and on the command line, naming both
 * variables, before anything is served or touched.
 */
final class HeaderSignInBootTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function entryPoints(): iterable
    {
        yield 'web' => [['public/index.php']];
        yield 'CLI' => [['bin/auth.php', 'login-link', 'owner']];
        yield 'scheduled tasks' => [['bin/run-scheduled-tasks.php']];
    }

    /**
     * @param list<string> $command the script and its arguments
     */
    #[DataProvider('entryPoints')]
    public function testAHeaderWithoutTrustedProxiesRefusesToStart(array $command): void
    {
        [$script] = $command;
        $env = getenv();
        $env['AUTH_PROXY_HEADER'] = 'Remote-User';
        $env['AUTH_PROXY_TRUSTED'] = '';
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', Kernel::rootDir() . '/' . $script, ...array_slice($command, 1)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Kernel::rootDir(),
            $env,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertNotSame(0, proc_close($process));
        self::assertStringContainsString('AUTH_PROXY_HEADER is set but AUTH_PROXY_TRUSTED is empty', (string) $output);
    }
}
