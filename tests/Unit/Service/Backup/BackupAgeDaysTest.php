<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Service\Backup\BackupAge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The backup's age in calendar days in the viewer's time zone (spec.md
 * §7.13), across a clock change and for a time in the future.
 */
final class BackupAgeDaysTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function cases(): iterable
    {
        yield 'same day' => ['2026-10-13T00:30:00Z', '2026-10-13T22:00:00Z', 'UTC', 0];
        yield 'yesterday, an hour ago' => ['2026-10-12T23:30:00Z', '2026-10-13T00:30:00Z', 'UTC', 1];
        yield 'the same, both 13 October in London' => ['2026-10-12T23:30:00Z', '2026-10-13T00:30:00Z', 'Europe/London', 0];
        yield 'across the end of summer time' => ['2026-10-20T12:00:00Z', '2026-11-03T12:00:00Z', 'Europe/London', 14];
        yield 'across the start of summer time' => ['2026-03-28T12:00:00Z', '2026-03-30T12:00:00Z', 'America/New_York', 2];
        yield 'east of UTC' => ['2026-10-12T15:30:00Z', '2026-10-13T08:00:00Z', 'Pacific/Auckland', 0];
        yield 'a time in the future' => ['2026-10-20T12:00:00Z', '2026-10-13T12:00:00Z', 'UTC', 0];
    }

    #[DataProvider('cases')]
    public function testDays(string $from, string $to, string $zone, int $expected): void
    {
        self::assertSame(
            $expected,
            BackupAge::days(new DateTimeImmutable($from), new DateTimeImmutable($to), new DateTimeZone($zone)),
        );
    }
}
