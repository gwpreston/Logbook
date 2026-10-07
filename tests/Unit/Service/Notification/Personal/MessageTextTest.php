<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification\Personal;

use Closure;
use Logbook\Service\Notification\Personal\MessageText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fitting a message into a service's limit (spec.md §7.11): code points,
 * cut between lines with "…and N more" and the link, never over.
 */
final class MessageTextTest extends TestCase
{
    private const string LINK = 'https://garage.example/reminders';

    public function testAMessageThatFitsIsUnchanged(): void
    {
        $fitted = MessageText::fit(['Title', '', '• One', '• Two', ''], 100, self::LINK, self::more());
        self::assertSame("Title\n\n• One\n• Two\n\n" . self::LINK, $fitted);
        self::assertSame("Title\n• One", MessageText::fit(['Title', '• One'], 100, null, self::more()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function limits(): iterable
    {
        yield 'Telegram' => [4096];
        yield 'Discord' => [2000];
        yield 'Pushover' => [1024];
        yield 'Slack' => [4000];
        yield 'Mattermost' => [16383];
    }

    #[DataProvider('limits')]
    public function testADigestOf200ItemsFitsEachLimitAndCountsWhatIsLeft(int $limit): void
    {
        $lines = ['Due in October 2026', '', 'These need your attention:', ''];
        for ($i = 1; $i <= 200; $i++) {
            $lines[] = sprintf('• Service %03d — Golf GTI: due in %d days (2 Oct 2026), ', $i, $i)
                . 'a note 🚗 with ünïcödé and a little more text';
        }

        $text = MessageText::fit($lines, $limit, self::LINK, self::more());

        self::assertLessThanOrEqual($limit, mb_strlen($text, 'UTF-8'));
        self::assertStringEndsWith("\n\n" . self::LINK, $text);
        self::assertSame(1, preg_match('/\n…and (\d+) more\n\n/', $text, $m));
        $kept = substr_count($text, '• Service');
        self::assertSame(200, $kept + (int) ($m[1] ?? 0), 'every item is either sent or counted');
        self::assertStringNotContainsString('• Service ' . sprintf('%03d', $kept + 1), $text, 'cut at a line, not mid-line');
        // Nearly full: one more line would not have fitted.
        self::assertGreaterThan($limit - 120, mb_strlen($text, 'UTF-8'));
    }

    public function testCodePointsAreCountedNotBytes(): void
    {
        $line = str_repeat('🚗', 10);
        self::assertSame(10, MessageText::length($line));
        self::assertSame($line, MessageText::fit([$line], 10, null, self::more()), '40 bytes, 10 code points: fits');
        self::assertSame(str_repeat('é', 9) . '…', MessageText::cut(str_repeat('é', 20), 10));
    }

    public function testASingleOversizedLineIsCutWithAnEllipsis(): void
    {
        $text = MessageText::fit([str_repeat('x', 500)], 100, self::LINK, self::more());

        self::assertLessThanOrEqual(100, mb_strlen($text));
        self::assertStringEndsWith('…' . "\n\n" . self::LINK, $text);
    }

    public function testAnOversizedFirstLineIsCutAndTheRestCounted(): void
    {
        $text = MessageText::fit([str_repeat('x', 500), '• a', '• b'], 100, null, self::more());

        self::assertLessThanOrEqual(100, mb_strlen($text));
        self::assertStringEndsWith("…\n…and 2 more", $text);
    }

    public function testALinkLongerThanTheLimitLeavesTheTextAlone(): void
    {
        $text = MessageText::fit(['Title', '• a'], 20, str_repeat('l', 30), self::more());

        self::assertSame("Title\n• a", $text);
        self::assertSame('', MessageText::cut('abc', 0));
    }

    /**
     * @return Closure(int): string
     */
    private static function more(): Closure
    {
        return static fn (int $n): string => sprintf('…and %d more', $n);
    }
}
