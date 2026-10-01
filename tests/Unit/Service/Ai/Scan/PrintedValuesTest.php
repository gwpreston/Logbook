<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Service\Ai\Scan\PrintedDate;
use Logbook\Service\Ai\Scan\PrintedNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Values as printed, read in the user's locale (spec.md §7.27).
 */
final class PrintedValuesTest extends TestCase
{
    public function testAnAmbiguousDateIsReadDayFirstInTheUkAndOfferedTheOtherWay(): void
    {
        $read = PrintedDate::read('04/05/2026', 'en_GB');

        self::assertNotNull($read);
        self::assertSame('2026-05-04', $read->date->format('Y-m-d'));
        self::assertSame('2026-04-05', $read->alternative?->format('Y-m-d'));
    }

    public function testTheSameDateIsReadMonthFirstInTheUs(): void
    {
        $read = PrintedDate::read('04/05/2026', 'en_US');

        self::assertNotNull($read);
        self::assertSame('2026-04-05', $read->date->format('Y-m-d'));
        self::assertSame('2026-05-04', $read->alternative?->format('Y-m-d'));
    }

    public function testADayOver12IsNotAmbiguousInEitherOrder(): void
    {
        $uk = PrintedDate::read('14/03/2026', 'en_GB');
        self::assertNotNull($uk);
        self::assertSame('2026-03-14', $uk->date->format('Y-m-d'));
        self::assertNull($uk->alternative);
        self::assertSame('2026-03-14', PrintedDate::read('14/03/2026', 'en_US')?->date->format('Y-m-d'));
        self::assertNull(PrintedDate::read('05/05/2026', 'en_GB')?->alternative, 'the same both ways');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dates(): iterable
    {
        yield 'iso' => ['2026-09-12', 'en_US', '2026-09-12'];
        yield 'german dots' => ['12.09.26', 'de_DE', '2026-09-12'];
        yield 'words' => ['12th September 2026', 'en_GB', '2026-09-12'];
        yield 'abbreviated' => ['12 Sep 2026', 'en_GB', '2026-09-12'];
        yield 'us words' => ['September 12, 2026', 'en_US', '2026-09-12'];
        yield 'german words' => ['12. März 2026', 'de_DE', '2026-03-12'];
        yield 'inside a line' => ['Invoice date: 12/09/2026 14:02', 'en_GB', '2026-09-12'];
    }

    #[DataProvider('dates')]
    public function testDatesAsPrinted(string $printed, string $locale, string $expected): void
    {
        self::assertSame($expected, PrintedDate::read($printed, $locale)?->date->format('Y-m-d'));
    }

    public function testNotADate(): void
    {
        self::assertNull(PrintedDate::read('31/02/2026', 'en_GB'));
        self::assertNull(PrintedDate::read('Total £184.50', 'en_GB'));
    }

    public function testTimes(): void
    {
        self::assertSame([14, 32], PrintedDate::time('12/09/2026 14:32'));
        self::assertSame([14, 32], PrintedDate::time('2:32 pm'));
        self::assertNull(PrintedDate::time('no time'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numbers(): iterable
    {
        yield 'pounds' => ['£184.50', 'en_GB', '184.50'];
        yield 'grouped miles' => ['48,120 miles', 'en_GB', '48120'];
        yield 'spaced' => ['48 120 km', 'fr_FR', '48120'];
        yield 'german decimal' => ['45,12 l', 'de_DE', '45.12'];
        yield 'german grouped' => ['1.234,56 €', 'de_DE', '1234.56'];
        yield 'english grouped' => ['1,234.56', 'en_GB', '1234.56'];
        yield 'german three decimals' => ['45,123', 'de_DE', '45.123'];
        yield 'price per litre' => ['142.9p', 'en_GB', '142.9'];
        yield 'zero' => ['£0.00', 'en_GB', '0.00'];
    }

    #[DataProvider('numbers')]
    public function testNumbersAsPrinted(string $printed, string $locale, string $expected): void
    {
        self::assertSame($expected, PrintedNumber::read($printed, $locale));
    }

    public function testNotANumber(): void
    {
        self::assertNull(PrintedNumber::read('n/a', 'en_GB'));
    }
}
