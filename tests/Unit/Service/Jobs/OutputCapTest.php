<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Jobs;

use Logbook\Service\Jobs\OutputCap;
use PHPUnit\Framework\TestCase;

/**
 * A run's stored output is at most 64 KB (spec.md §6 JobRun): the first
 * and last 32 KB, whole lines, and how many lines were left out.
 */
final class OutputCapTest extends TestCase
{
    public function testShortOutputIsKeptWhole(): void
    {
        self::assertSame("one\ntwo", OutputCap::join(['one', 'two']));
    }

    public function testLongOutputKeepsBothEndsWithTheGapNote(): void
    {
        $lines = [];
        for ($i = 1; $i <= 2000; $i++) {
            $lines[] = sprintf('[10:00:00] line %04d %s', $i, str_repeat('x', 80));
        }

        $output = OutputCap::join($lines);

        self::assertLessThanOrEqual(OutputCap::LIMIT, strlen($output));
        self::assertStringStartsWith($lines[0] . "\n", $output);
        self::assertStringEndsWith("\n" . $lines[1999], $output);
        self::assertSame(1, preg_match('/\n… (\d+) lines left out …\n/', $output, $m));
        // Lines in the output, less the note.
        $kept = substr_count($output, "\n");
        self::assertSame(2000, $kept + (int) ($m[1] ?? 0), 'every line is either kept or counted');
    }

    public function testOneHugeLineKeepsItsEndsOnACharacter(): void
    {
        $output = OutputCap::join([str_repeat('é', 50000)]);

        self::assertLessThanOrEqual(OutputCap::LIMIT, strlen($output));
        self::assertTrue(mb_check_encoding($output, 'UTF-8'), 'never cut inside a character');
        self::assertStringContainsString('lines left out', $output);
    }
}
