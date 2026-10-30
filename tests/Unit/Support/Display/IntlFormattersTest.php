<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Display;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Logbook\Support\Display\IntlFormatters;
use NumberFormatter;
use PHPUnit\Framework\TestCase;

/**
 * intl formatters are made once per locale and settings (Phase 54.5,
 * K150), and a reused one formats exactly as a new one would.
 */
final class IntlFormattersTest extends TestCase
{
    public function testANumberFormatterIsMadeOncePerLocaleAndSettings(): void
    {
        $formatters = new IntlFormatters();
        $pounds = $formatters->number('en_GB', NumberFormatter::CURRENCY, 3, 2);

        self::assertSame($pounds, $formatters->number('en_GB', NumberFormatter::CURRENCY, 3, 2));
        self::assertNotSame($pounds, $formatters->number('en_GB', NumberFormatter::CURRENCY, 2, 2), 'other digits');
        self::assertNotSame($pounds, $formatters->number('de_DE', NumberFormatter::CURRENCY, 3, 2), 'another locale');
        self::assertNotSame($pounds, $formatters->number('en_GB', NumberFormatter::CURRENCY, 3), 'the style\'s own minimum');
        self::assertNotSame($pounds, $formatters->number('en_GB', NumberFormatter::DECIMAL, 3, 2), 'another style');
    }

    public function testAReusedNumberFormatterFormatsAsANewOne(): void
    {
        $formatters = new IntlFormatters();
        foreach (['en_GB', 'de_DE', 'en_US'] as $locale) {
            $fresh = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $fresh->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
            $fresh->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 3);
            $fresh->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
            foreach ([0.0, 0.0125, 1.4595, -12.3456, 1234567.8905] as $value) {
                self::assertSame(
                    $fresh->formatCurrency($value, 'EUR'),
                    $formatters->number($locale, NumberFormatter::CURRENCY, 3, 2)->formatCurrency($value, 'EUR'),
                    "$locale $value",
                );
            }
        }
    }

    public function testADateFormatterIsMadeOncePerLocaleZoneAndPattern(): void
    {
        $formatters = new IntlFormatters();
        $none = IntlDateFormatter::NONE;
        $day = $formatters->date('en_GB', $none, $none, 'UTC', 'd MMM');

        self::assertSame($day, $formatters->date('en_GB', $none, $none, 'UTC', 'd MMM'));
        self::assertNotSame($day, $formatters->date('en_GB', $none, $none, 'Europe/London', 'd MMM'));
        self::assertNotSame($day, $formatters->date('en_GB', $none, $none, 'UTC', 'LLL'));
        self::assertNotSame($day, $formatters->date('en_GB', IntlDateFormatter::MEDIUM, $none, 'UTC'));
        self::assertSame('14 Mar', $day->format(new DateTimeImmutable('2026-03-14', new DateTimeZone('UTC'))));
    }

    public function testAPatternIsTheLocalesOrTheFallback(): void
    {
        $formatters = new IntlFormatters();

        self::assertSame('d MMM', $formatters->pattern('en_GB', 'dMMM', 'x'));
        self::assertSame('MMM d', $formatters->pattern('en_US', 'dMMM', 'x'));
        self::assertSame('MMM d', $formatters->pattern('en_US', 'dMMM', 'x'), 'asked again');
    }
}
