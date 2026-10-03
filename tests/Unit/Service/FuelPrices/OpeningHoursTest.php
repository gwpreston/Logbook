<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\FuelPrices;

use Logbook\Service\FuelPrices\OpeningHours;
use PHPUnit\Framework\TestCase;

/**
 * A feed's opening times as one OpenStreetMap-style line for a linked
 * station's hours (spec.md §7.34 *Linking stations*).
 */
final class OpeningHoursTest extends TestCase
{
    public function testDaysWithTheSameHoursShareARange(): void
    {
        $day = static fn (string $open, string $close, bool $allDay = false): array => ['open' => $open, 'close' => $close, 'is_24_hours' => $allDay];

        self::assertSame('Mo-Fr 06:00-22:00; Sa 07:00-21:00; Su off', OpeningHours::text(['usual_days' => [
            'monday' => $day('06:00', '22:00'),
            'tuesday' => $day('06:00', '22:00'),
            'wednesday' => $day('06:00', '22:00'),
            'thursday' => $day('06:00', '22:00'),
            'friday' => $day('06:00', '22:00'),
            'saturday' => $day('07:00', '21:00'),
            'sunday' => $day('00:00', '00:00'),
        ]]));
        self::assertSame('24/7', OpeningHours::text(['usual_days' => array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            $day('00:00', '00:00', true),
        )]));
        self::assertSame('Mo 06:00-24:00', OpeningHours::text(['usual_days' => ['monday' => $day('06:00', '00:00')]]));
    }

    public function testNothingKnownIsNoText(): void
    {
        self::assertNull(OpeningHours::text(null));
        self::assertNull(OpeningHours::text([]));
        self::assertNull(OpeningHours::text(['usual_days' => ['monday' => ['open' => null]]]));
    }
}
