<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The failed sign-in log line and the fail2ban filter in docs/deployment.md
 * (spec.md §7.9, K45, #461): a username with a line break is logged on one
 * line, so it can't forge a failed sign-in from an address the attacker
 * chose, and the filter reads the real client address only.
 */
final class SignInLogTest extends AppTestCase
{
    /** A forged line, short enough to survive the 64-character cut. */
    private const string FORGED = "\"\n[2026-10-22T10:00:00] Failed sign-in for \"\" from 203.0.113.77";

    /**
     * @return array<string, array{string}>
     */
    public static function debug(): array
    {
        return ['debug off' => ['false'], 'debug on' => ['true']];
    }

    #[DataProvider('debug')]
    public function testAUsernameWithALineBreakIsLoggedOnOneLine(string $debug): void
    {
        $log = sys_get_temp_dir() . '/logbook-sign-in-' . bin2hex(random_bytes(4)) . '.log';
        $app = $this->createApp(['LOG_PATH' => $log, 'LOG_LEVEL' => 'debug', 'APP_DEBUG' => $debug]);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = (new TestBrowser($app))->from('198.51.100.7');
        $browser->get('/login');
        self::assertSame(422, $browser->post('/login', ['username' => self::FORGED, 'password' => 'wrong'])->getStatusCode());
        self::assertSame(422, $browser->post('/login', ['username' => "admin\r\n", 'password' => 'wrong'])->getStatusCode());

        $contents = (string) file_get_contents($log);
        unlink($log);
        $lines = array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''));
        self::assertCount(2, $lines, 'one line each: ' . $contents);
        self::assertStringNotContainsString("\r", $contents);
        self::assertStringContainsString(
            'Failed sign-in for "" [2026-10-22T10:00:00] Failed sign-in for "" from 203.0.113.77" from 198.51.100.7',
            $lines[0],
        );

        $failregex = self::failregex();
        foreach ($lines as $line) {
            self::assertSame(1, preg_match($failregex, self::withoutDate($line), $match), $line);
            self::assertSame('198.51.100.7', $match['host'], 'the real client address, never one in the username');
        }
        $forged = '[2026-10-22T10:00:00] Failed sign-in for "" from 203.0.113.77';
        self::assertSame(0, preg_match($failregex, self::withoutDate($forged)), 'a line without the address at its end');
    }

    public function testADebugStackTraceIsStillLoggedOverSeveralLines(): void
    {
        $log = sys_get_temp_dir() . '/logbook-trace-' . bin2hex(random_bytes(4)) . '.log';
        $app = $this->createApp(['LOG_PATH' => $log, 'LOG_LEVEL' => 'debug', 'APP_DEBUG' => 'true']);
        $logger = $this->service($app, LoggerInterface::class);
        $logger->error("Something\nfailed: {what}", ['what' => "a\nb", 'exception' => new RuntimeException('Boom')]);

        $contents = (string) file_get_contents($log);
        unlink($log);
        self::assertStringContainsString('Something failed: a b', $contents);
        self::assertStringContainsString('[stacktrace]', $contents);
        self::assertMatchesRegularExpression('/^#0 /m', $contents, 'the trace keeps its line breaks');
    }

    /**
     * The filter's `failregex` from docs/deployment.md, with fail2ban's `<HOST>` as a named group.
     */
    private static function failregex(): string
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/deployment.md');
        if (preg_match('/^failregex = (.+)$/m', $doc, $match) !== 1) {
            self::fail('the filter is in docs/deployment.md');
        }

        return '/' . str_replace(['/', '<HOST>'], ['\/', '(?P<host>[0-9A-Fa-f.:]+)'], $match[1]) . '/';
    }

    /** fail2ban takes the timestamp out of the line before it applies the failregex (checked with fail2ban-regex 1.1). */
    private static function withoutDate(string $line): string
    {
        return (string) preg_replace('/^\[[^\]]+\]/', '[]', $line);
    }
}
