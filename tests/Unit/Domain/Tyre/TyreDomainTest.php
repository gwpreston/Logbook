<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Domain\Tyre;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Tyre\DotCode;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DOT codes, positions by vehicle type and size normalisation (spec.md §7.17).
 */
final class TyreDomainTest extends TestCase
{
    private static function today(): DateTimeImmutable
    {
        $date = LocalTime::parseDate('2026-09-29');
        assert($date !== null);

        return $date;
    }

    public function testCodeIsTheMondayOfItsIsoWeek(): void
    {
        $dot = DotCode::parse('2323', self::today());

        self::assertInstanceOf(DotCode::class, $dot);
        self::assertSame('2323', $dot->code);
        self::assertSame('2023-06-05', $dot->manufacturedOn->format('Y-m-d'), 'Monday of ISO week 23, 2023');
        self::assertSame('1', $dot->manufacturedOn->format('N'));
    }

    public function testWeekOneMayStartInTheYearBefore(): void
    {
        $dot = DotCode::parse('0120', self::today());

        self::assertInstanceOf(DotCode::class, $dot);
        self::assertSame('2019-12-30', $dot->manufacturedOn->format('Y-m-d'), 'ISO week 1 of 2020 starts on 30 Dec 2019');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'week 00' => ['0023', 'tyre.error.dot_week'];
        yield 'week 54' => ['5423', 'tyre.error.dot_week'];
        yield 'week 53 in a year without one' => ['5323', 'tyre.error.dot_week'];
        yield 'three digits (before 2000)' => ['239', 'tyre.error.dot_format'];
        yield 'five digits' => ['23230', 'tyre.error.dot_format'];
        yield 'letters' => ['23A3', 'tyre.error.dot_format'];
        yield 'next year' => ['0127', 'tyre.error.dot_future'];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesAreRejected(string $code, string $error): void
    {
        self::assertSame($error, DotCode::parse($code, self::today()));
    }

    public function testWeek53ExistsInAYearThatHasOne(): void
    {
        $dot = DotCode::parse('5320', self::today());

        self::assertInstanceOf(DotCode::class, $dot);
        self::assertSame('2020-12-28', $dot->manufacturedOn->format('Y-m-d'));
    }

    public function testACodeInTheOwnersFutureIsRejected(): void
    {
        // Monday 5 Oct 2026 is week 41. Just after midnight in Auckland it is
        // already that Monday there, while it is still Sunday in London.
        $clock = new MutableClock(new DateTimeImmutable('2026-10-04T12:30:00Z'));
        $auckland = LocalTime::today($clock, new DateTimeZone('Pacific/Auckland'));
        $london = LocalTime::today($clock, new DateTimeZone('Europe/London'));

        self::assertInstanceOf(DotCode::class, DotCode::parse('4126', $auckland));
        self::assertSame('tyre.error.dot_future', DotCode::parse('4126', $london));
    }

    public function testManufactureDateIsNeverShiftedByTheTimeZone(): void
    {
        $default = date_default_timezone_get();
        try {
            foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Europe/London'] as $zone) {
                date_default_timezone_set($zone);
                $dot = DotCode::parse('2323', self::today());
                self::assertInstanceOf(DotCode::class, $dot);
                self::assertSame('2023-06-05 00:00:00 UTC', $dot->manufacturedOn->format('Y-m-d H:i:s T'), $zone);
                self::assertEquals(LocalTime::parseDate('2023-06-05'), $dot->manufacturedOn);
            }
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testPositionsComeFromTheVehicleType(): void
    {
        self::assertSame(
            [
                TyrePosition::FrontLeft,
                TyrePosition::FrontRight,
                TyrePosition::RearLeft,
                TyrePosition::RearRight,
                TyrePosition::Spare,
            ],
            VehicleType::Car->tyrePositions(),
        );
        self::assertSame([TyrePosition::Front, TyrePosition::Rear], VehicleType::Bike->tyrePositions());
        self::assertFalse(TyrePosition::Spare->isRolling());
        self::assertTrue(TyrePosition::RearLeft->isRolling());
        self::assertSame('front', TyrePosition::FrontRight->axle());
        self::assertSame('rear', TyrePosition::Rear->axle());
        self::assertNull(TyrePosition::Spare->axle());
    }

    public function testSizeIsNormalised(): void
    {
        self::assertSame('205/55 R16 91V', TyreData::normaliseSize("  205/55   r16\t91v "));
        self::assertSame('120/70 ZR17', TyreData::normaliseSize('120/70 zr17'));
    }
}
