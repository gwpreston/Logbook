<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\ListedPrice;
use PHPUnit\Framework\TestCase;

/**
 * Freshness (spec.md §7.34): a price reported more than 48 hours ago may
 * be out of date and is left out of rankings unless asked.
 */
final class ListedPriceTest extends TestCase
{
    public function testFortyEightHoursIsTheLimit(): void
    {
        $now = new DateTimeImmutable('2026-10-03T12:00:00Z');
        $listed = static fn (string $at): ListedPrice => new ListedPrice(FuelGrade::E10_95, '1.379', new DateTimeImmutable($at));

        self::assertTrue($listed('2026-10-03T11:00:00Z')->isFresh($now));
        self::assertTrue($listed('2026-10-01T12:00:00Z')->isFresh($now), 'exactly 48 hours');
        self::assertFalse($listed('2026-10-01T11:59:59Z')->isFresh($now));
        self::assertTrue($listed('2026-10-03T12:04:00Z')->isFresh($now), 'a clock a few minutes ahead');
        self::assertFalse($listed('2026-10-03T13:00:00Z')->isFresh($now), 'an hour in the future is wrong');
    }
}
