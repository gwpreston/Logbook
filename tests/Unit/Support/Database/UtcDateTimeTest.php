<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Logbook\Support\Database\UtcDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class UtcDateTimeTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function platforms(): iterable
    {
        yield 'postgres' => [new PostgreSQLPlatform()];
        yield 'mysql' => [new MySQL84Platform()];
        yield 'sqlite' => [new SQLitePlatform()];
    }

    #[DataProvider('platforms')]
    public function testLocalTimeIsStoredAsUtc(AbstractPlatform $platform): void
    {
        // 09:30 in London during BST is 08:30 UTC.
        $local = new DateTimeImmutable('2026-07-01 09:30:00', new DateTimeZone('Europe/London'));

        self::assertSame('2026-07-01 08:30:00', UtcDateTime::toDatabase($local, $platform));
    }

    #[DataProvider('platforms')]
    public function testDateLineCrossingKeepsTheUtcDate(AbstractPlatform $platform): void
    {
        // Just after midnight in Auckland is still the previous day in UTC.
        $local = new DateTimeImmutable('2026-01-01 00:15:00', new DateTimeZone('Pacific/Auckland'));

        self::assertSame('2025-12-31 11:15:00', UtcDateTime::toDatabase($local, $platform));
    }

    #[DataProvider('platforms')]
    public function testReadsBackAsUtcRegardlessOfPhpDefaultZone(AbstractPlatform $platform): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $value = UtcDateTime::fromDatabase('2026-03-29 01:30:00', $platform);
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertSame('UTC', $value->getTimezone()->getName());
        self::assertSame('2026-03-29T01:30:00+00:00', $value->format(DATE_ATOM));
    }

    public function testToleratesFractionalSeconds(): void
    {
        $value = UtcDateTime::fromDatabase('2026-03-29 01:30:00.123456', new PostgreSQLPlatform());

        self::assertSame('2026-03-29 01:30:00', $value->format('Y-m-d H:i:s'));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(UnexpectedValueException::class);
        UtcDateTime::fromDatabase('yesterday-ish', new PostgreSQLPlatform());
    }
}
