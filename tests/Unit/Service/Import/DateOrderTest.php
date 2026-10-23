<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import;

use Logbook\Service\Import\DateOrder;
use PHPUnit\Framework\TestCase;

/**
 * A date written year first is read under every date order (#459, K57): a
 * four-digit year first can't be mistaken for a day or a month. Each order
 * still reads its own dates as before.
 */
final class DateOrderTest extends TestCase
{
    public function testAYearFirstDateIsReadUnderEveryOrder(): void
    {
        foreach (DateOrder::cases() as $order) {
            self::assertSame('2026-04-06', $order->normalise('2026-04-06'), $order->value);
            self::assertSame('2026-04-06', $order->normalise(' 2026/4/6 '), $order->value);
            self::assertNull($order->normalise('2026-02-30'), $order->value . ': no such day');
        }
    }

    public function testEachOrderStillReadsItsOwnDates(): void
    {
        self::assertSame('2026-04-06', DateOrder::DayFirst->normalise('06/04/2026'));
        self::assertSame('2026-06-04', DateOrder::MonthFirst->normalise('06/04/2026'));
        self::assertSame('2026-09-27', DateOrder::DayFirst->normalise('27.09.2026'));
        self::assertNull(DateOrder::MonthFirst->normalise('27/09/2026'), 'no 27th month');
        self::assertNull(DateOrder::Iso->normalise('06/04/2026'), 'ISO reads year first only');
        self::assertNull(DateOrder::DayFirst->normalise('26-04-06'), 'a two-digit year is not read');
    }
}
