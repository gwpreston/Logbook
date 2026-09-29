<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Vehicle;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Service\Vehicle\StartingReading;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * The add form's starting reading (spec.md §7.1): read today → the moment
 * of saving; an earlier *As of* → local noon on that date, so it stays on
 * that day whichever side of UTC the owner is.
 */
final class StartingReadingTest extends TestCase
{
    public function testTodayOrNoDateIsTheMomentOfSaving(): void
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');
        $zone = new DateTimeZone('Europe/London');

        self::assertSame($now, (new StartingReading('100.000'))->recordedAt($now, $zone));
        self::assertSame($now, (new StartingReading('100.000', self::date('2026-09-27')))->recordedAt($now, $zone));
    }

    public function testTodayIsTheOwnersToday(): void
    {
        // 11:30 UTC on 27 September is already 28 September (00:30) in Auckland.
        $now = new DateTimeImmutable('2026-09-27T11:30:00Z');
        $auckland = new DateTimeZone('Pacific/Auckland');

        self::assertSame($now, (new StartingReading('1.000', self::date('2026-09-28')))->recordedAt($now, $auckland));
        self::assertSame(
            '2026-09-26T23:00:00Z',
            (new StartingReading('1.000', self::date('2026-09-27')))->recordedAt($now, $auckland)->format('Y-m-d\TH:i:s\Z'),
            'the day before, in Auckland, is local noon on it',
        );
    }

    public function testAnEarlierDateIsLocalNoonAheadOfAndBehindUtc(): void
    {
        $now = new DateTimeImmutable('2026-09-27T10:00:00Z');
        $reading = new StartingReading('48280.320', self::date('2026-03-14'));

        foreach (
            [
            'Pacific/Auckland' => '2026-03-13T23:00:00Z',
            'Europe/London' => '2026-03-14T12:00:00Z',
            'America/Los_Angeles' => '2026-03-14T19:00:00Z',
            ] as $zone => $utc
        ) {
            $zone = new DateTimeZone($zone);
            $at = $reading->recordedAt($now, $zone);
            self::assertSame($utc, $at->format('Y-m-d\TH:i:s\Z'), $zone->getName());
            self::assertSame('2026-03-14', LocalTime::dateOf($at, $zone)->format('Y-m-d'), 'on its date locally');
        }
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
