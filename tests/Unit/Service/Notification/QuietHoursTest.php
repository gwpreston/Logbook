<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Service\Notification\QuietHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Quiet hours (spec.md §7.11, Phase 36.4): from the start up to, not
 * including, the end, by the wall clock in the user's time zone.
 */
final class QuietHoursTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function times(): iterable
    {
        yield 'same day, inside' => ['12:00', '14:00', '2026-07-01T12:30:00Z', true];
        yield 'same day, at the start' => ['12:00', '14:00', '2026-07-01T12:00:00Z', true];
        yield 'same day, at the end' => ['12:00', '14:00', '2026-07-01T14:00:00Z', false];
        yield 'same day, before' => ['12:00', '14:00', '2026-07-01T11:59:00Z', false];
        yield 'past midnight, late' => ['22:00', '07:00', '2026-07-01T23:15:00Z', true];
        yield 'past midnight, early' => ['22:00', '07:00', '2026-07-01T06:59:00Z', true];
        yield 'past midnight, at the end' => ['22:00', '07:00', '2026-07-01T07:00:00Z', false];
        yield 'past midnight, daytime' => ['22:00', '07:00', '2026-07-01T15:00:00Z', false];
    }

    #[DataProvider('times')]
    public function testContains(string $start, string $end, string $now, bool $quiet): void
    {
        $hours = QuietHours::of($start, $end);
        self::assertNotNull($hours);
        self::assertSame($quiet, $hours->contains(new DateTimeImmutable($now), new DateTimeZone('UTC')));
    }

    public function testTheUsersTimeZoneDecides(): void
    {
        $hours = QuietHours::of('22:00', '07:00');
        self::assertNotNull($hours);
        // 21:30 UTC in July is 22:30 in London (BST) and 17:30 in New York.
        $now = new DateTimeImmutable('2026-07-01T21:30:00Z');

        self::assertTrue($hours->contains($now, new DateTimeZone('Europe/London')));
        self::assertFalse($hours->contains($now, new DateTimeZone('America/New_York')));
    }

    public function testOnTheDayTheClocksChangeTheWallClockDecides(): void
    {
        $hours = QuietHours::of('00:30', '02:30');
        self::assertNotNull($hours);
        $london = new DateTimeZone('Europe/London');

        // 29 March 2026: 01:00 GMT becomes 02:00 BST, so 00:30 to 02:30 is one real hour.
        self::assertTrue($hours->contains(new DateTimeImmutable('2026-03-29T00:45:00Z'), $london), '00:45 GMT');
        self::assertTrue($hours->contains(new DateTimeImmutable('2026-03-29T01:15:00Z'), $london), '02:15 BST');
        self::assertFalse($hours->contains(new DateTimeImmutable('2026-03-29T01:30:00Z'), $london), '02:30 BST');
        // 25 October 2026: 02:00 BST becomes 01:00 GMT, so it is three real hours.
        self::assertTrue($hours->contains(new DateTimeImmutable('2026-10-25T01:20:00Z'), $london), '01:20 GMT, the second time');
        self::assertFalse($hours->contains(new DateTimeImmutable('2026-10-25T02:30:00Z'), $london), '02:30 GMT');
    }

    public function testOnlyValidDifferentTimes(): void
    {
        self::assertNull(QuietHours::of('22:00', '22:00'), 'start and end must differ');
        self::assertNull(QuietHours::of('24:00', '07:00'));
        self::assertNull(QuietHours::of('7:00', '08:00'));
        self::assertNull(QuietHours::of('', '08:00'));
        self::assertNotNull(QuietHours::of('00:00', '23:59'));
    }

    public function testStoredRoundTrip(): void
    {
        $hours = QuietHours::of('22:15', '06:45');
        self::assertNotNull($hours);
        self::assertSame(['start' => '22:15', 'end' => '06:45'], $hours->toStored());
        self::assertEquals($hours, QuietHours::fromStored($hours->toStored()));
        self::assertNull(QuietHours::fromStored(null));
        self::assertNull(QuietHours::fromStored(['start' => '22:00']));
        self::assertNull(QuietHours::fromStored(['start' => 'late', 'end' => '07:00']));
    }
}
