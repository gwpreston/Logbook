<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Log;

use DateTimeImmutable;
use Logbook\Support\Log\OneLineProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;

/**
 * Outside text on one log line (K45, #461): CR and LF in the message and in
 * string context values become a space; everything else is left as it is.
 */
final class OneLineProcessorTest extends TestCase
{
    public function testLineBreaksInTheMessageAndStringContextBecomeSpaces(): void
    {
        $exception = new RuntimeException("Boom\nagain");
        $address = new class implements Stringable {
            public function __toString(): string
            {
                return "203.0.113.9\r\nforged";
            }
        };
        $record = new LogRecord(
            new DateTimeImmutable('2026-10-22T10:00:00Z'),
            'logbook',
            Level::Notice,
            "Failed sign-in\rfor {username}",
            ['username' => "a\nb\r\nc", 'ip' => $address, 'count' => 3, 'list' => ["x\ny"], 'exception' => $exception],
        );

        $line = (new OneLineProcessor())($record);

        self::assertSame('Failed sign-in for {username}', $line->message);
        self::assertSame('a b c', $line->context['username']);
        self::assertSame('203.0.113.9 forged', $line->context['ip']);
        self::assertSame(3, $line->context['count']);
        self::assertSame(["x\ny"], $line->context['list'], 'an array is written as JSON, which escapes it');
        self::assertSame($exception, $line->context['exception'], 'an exception keeps its trace');
    }

    public function testTextWithoutLineBreaksIsUnchanged(): void
    {
        self::assertSame('Failed sign-in for "admin" from 203.0.113.9', OneLineProcessor::line('Failed sign-in for "admin" from 203.0.113.9'));
    }
}
