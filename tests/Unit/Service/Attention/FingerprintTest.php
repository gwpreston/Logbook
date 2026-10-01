<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Attention;

use DateTimeImmutable;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Service\Attention\Fingerprint;
use Logbook\Support\Date\LocalTime;
use PHPUnit\Framework\TestCase;

/**
 * What a hidden check judged (spec.md §7.24 *Hiding*): any change to it
 * gives another fingerprint; the same state gives the same one.
 */
final class FingerprintTest extends TestCase
{
    public function testAReadingChangesWithItselfAndEitherNeighbour(): void
    {
        $before = self::reading(1, '10000', '2026-05-01T09:00:00Z');
        $flagged = self::reading(2, '9000', '2026-06-01T09:00:00Z');
        $after = self::reading(3, '9500', '2026-07-01T09:00:00Z');
        $print = Fingerprint::reading($before, $flagged, $after);

        self::assertSame(64, strlen($print));
        self::assertSame($print, Fingerprint::reading($before, $flagged, $after), 'stable');
        $changed = [
            'the reading' => [$before, self::reading(2, '9001', '2026-06-01T09:00:00Z'), $after],
            'its time' => [$before, self::reading(2, '9000', '2026-06-02T09:00:00Z'), $after],
            'the one before' => [self::reading(1, '10100', '2026-05-01T09:00:00Z'), $flagged, $after],
            'the one after' => [$before, $flagged, self::reading(3, '9600', '2026-07-01T09:00:00Z')],
            'one added between' => [$before, $flagged, self::reading(4, '9200', '2026-06-15T09:00:00Z')],
        ];
        foreach ($changed as $what => [$a, $b, $c]) {
            self::assertNotSame($print, Fingerprint::reading($a, $b, $c), $what);
        }
        self::assertNotSame($print, Fingerprint::reading($before, $flagged, null), 'the next one deleted');
    }

    public function testMileageAndValuationFollowTheLatest(): void
    {
        $latest = self::reading(5, '20000', '2026-07-31T09:00:00Z');
        self::assertSame(Fingerprint::mileage($latest), Fingerprint::mileage($latest));
        self::assertNotSame(Fingerprint::mileage($latest), Fingerprint::mileage(null));
        $another = self::reading(6, '20000', '2026-07-31T09:00:00Z');
        self::assertNotSame(Fingerprint::mileage($latest), Fingerprint::mileage($another));

        $valuation = self::valuation(1, '2025-04-01', '15000.000');
        $print = Fingerprint::valuation($valuation);
        self::assertSame($print, Fingerprint::valuation(self::valuation(1, '2025-04-01', '15000.000')));
        self::assertNotSame($print, Fingerprint::valuation(self::valuation(2, '2025-04-01', '15000.000')));
        self::assertNotSame($print, Fingerprint::valuation(self::valuation(1, '2025-04-02', '15000.000')));
        self::assertNotSame($print, Fingerprint::valuation(self::valuation(1, '2025-04-01', '14000.000')));
    }

    private static function reading(int $id, string $km, string $utc): OdometerReading
    {
        $at = new DateTimeImmutable($utc);

        return new OdometerReading($id, 1, $km . '.000', $at, OdometerSource::Manual, null, null, $at, $at);
    }

    private static function valuation(int $id, string $date, string $amount): VehicleValuation
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');

        return new VehicleValuation($id, 1, new VehicleValuationData($day, $amount), $now, $now);
    }
}
