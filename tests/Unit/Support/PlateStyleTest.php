<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support;

use Logbook\Support\PlateStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which plate an owner's locale gets, and what the plate shows (spec.md §8
 * *Registration plate*).
 */
final class PlateStyleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, PlateStyle}>
     */
    public static function locales(): iterable
    {
        yield 'en_GB' => ['en_GB', PlateStyle::Gb];
        yield 'cy_GB' => ['cy_GB', PlateStyle::Gb];
        yield 'en-GB (a hyphen)' => ['en-GB', PlateStyle::Gb];
        yield 'en_gb (a lower-case region)' => ['en_gb', PlateStyle::Gb];
        yield 'en_US' => ['en_US', PlateStyle::Neutral];
        yield 'de_DE' => ['de_DE', PlateStyle::Neutral];
        yield 'en_IE' => ['en_IE', PlateStyle::Neutral];
        yield 'en (no region)' => ['en', PlateStyle::Neutral];
        yield 'de (no region)' => ['de', PlateStyle::Neutral];
        yield 'unknown' => ['xx_YY_nonsense', PlateStyle::Neutral];
        yield 'empty' => ['', PlateStyle::Neutral];
    }

    #[DataProvider('locales')]
    public function testTheOwnersRegionPicksTheStyle(string $locale, PlateStyle $expected): void
    {
        self::assertSame($expected, PlateStyle::forLocale($locale));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function registrations(): iterable
    {
        yield 'as typed' => ['AB12 CDE', 'AB12 CDE'];
        yield 'upper case' => ['ab12 cde', 'AB12 CDE'];
        yield 'trimmed' => ['  AB12 CDE ', 'AB12 CDE'];
        yield 'whitespace collapsed' => ["AB12 \t\n  CDE", 'AB12 CDE'];
        yield 'never re-spaced' => ['AB12CDE', 'AB12CDE'];
        yield 'personalised' => ['b16 bob', 'B16 BOB'];
        yield 'accents upper-cased' => ['münchen 1', 'MÜNCHEN 1'];
        yield 'blank' => ['   ', ''];
        yield 'none' => [null, ''];
    }

    #[DataProvider('registrations')]
    public function testDisplayText(?string $registration, string $expected): void
    {
        self::assertSame($expected, PlateStyle::text($registration));
    }
}
