<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Number;

use InvalidArgumentException;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\DecimalParser;
use Logbook\Support\Number\NumberFormat;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function scaledInts(): iterable
    {
        yield 'exact' => ['12.34', 2, 1234];
        yield 'padded' => ['12.3', 3, 12300];
        yield 'rounds half up' => ['1.2345', 3, 1235];
        yield 'rounds half away from zero (negative)' => ['-1.2345', 3, -1235];
        yield 'rounds down below half' => ['1.2344', 3, 1234];
        yield 'zero' => ['0', 3, 0];
        yield 'leading zeros' => ['007.5', 1, 75];
        yield 'carry into whole part' => ['0.9995', 3, 1000];
    }

    #[DataProvider('scaledInts')]
    public function testToScaledInt(string $value, int $scale, int $expected): void
    {
        self::assertSame($expected, Decimal::toScaledInt($value, $scale));
    }

    public function testFromScaledInt(): void
    {
        self::assertSame('12.34', Decimal::fromScaledInt(1234, 2));
        self::assertSame('0.005', Decimal::fromScaledInt(5, 3));
        self::assertSame('-0.050', Decimal::fromScaledInt(-50, 3));
        self::assertSame('42', Decimal::fromScaledInt(42, 0));
    }

    public function testRoundCompareTrim(): void
    {
        self::assertSame('1.235', Decimal::round('1.2345', 3));
        self::assertSame('1.500', Decimal::round('1.5', 3));
        self::assertSame(0, Decimal::compare('1.50', '1.5'));
        self::assertSame(-1, Decimal::compare('-0.001', '0'));
        self::assertSame(1, Decimal::compare('10', '9.999'));
        self::assertSame('12.5', Decimal::trim('12.500'));
        self::assertSame('3', Decimal::trim('3.000'));
        self::assertSame('0', Decimal::trim('-0.000'));
    }

    public function testFromFloat(): void
    {
        self::assertSame('45.461', Decimal::fromFloat(45.4609, 3));
        self::assertSame('0.000', Decimal::fromFloat(-0.0001, 3));
    }

    public function testRejectsNonCanonicalAndHugeValues(): void
    {
        self::assertFalse(Decimal::isCanonical('1,5'));
        self::assertFalse(Decimal::isCanonical('.5'));

        $this->expectException(InvalidArgumentException::class);
        Decimal::toScaledInt('1e5', 2);
    }

    public function testOverflowIsDetected(): void
    {
        $this->expectException(OverflowException::class);
        Decimal::toScaledInt('123456789012345678', 3);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function parsed(): iterable
    {
        yield 'canonical' => ['1234.5', 'en', '1234.5'];
        yield 'canonical wins in German too' => ['1.5', 'de', '1.5'];
        yield 'leading dot' => ['.5', 'en', '0.5'];
        yield 'trailing dot' => ['5.', 'en', '5'];
        yield 'plus sign and spaces' => ['  +12 ', 'en', '12'];
        yield 'negative' => ['-3.25', 'en', '-3.25'];
        yield 'zero' => ['0', 'en', '0'];
        yield 'English grouping' => ['1,234.5', 'en', '1234.5'];
        yield 'German format' => ['1.234,5', 'de', '1234.5'];
        yield 'German leading decimal comma (K168)' => [',5', 'de', '0.5'];
        yield 'German negative leading decimal comma (K168)' => ['-,5', 'de', '-0.5'];
        yield 'German lone decimal comma (K168)' => [',', 'de', null];
        yield 'German decimal comma' => ['0,459', 'de', '0.459'];
        yield 'French narrow space grouping' => ["1\u{202F}234,5", 'fr', '1234.5'];
        yield 'plain space grouping' => ['1 234,5', 'fr', '1234.5'];
        yield 'misplaced grouping rejected' => ['1,5', 'en', null];
        yield 'two decimal points rejected' => ['1.2.3', 'en', null];
        yield 'letters rejected' => ['12abc', 'en', null];
        yield 'empty' => ['   ', 'en', null];
        yield 'lone sign' => ['-', 'en', null];
        yield 'the owner\'s locale still allows a long first group' => ['1234.567,5', 'de', '1234567.5'];
    }

    #[DataProvider('parsed')]
    public function testParser(string $input, string $locale, ?string $expected): void
    {
        self::assertSame($expected, DecimalParser::parse($input, $locale));
    }

    /**
     * @return iterable<string, array{string, NumberFormat, ?string}>
     */
    public static function formatted(): iterable
    {
        yield 'dot: comma is grouping' => ['45,123', NumberFormat::DotDecimal, '45123'];
        yield 'dot: dot is decimal' => ['13.000', NumberFormat::DotDecimal, '13.000'];
        yield 'dot: canonical still reads' => ['.5', NumberFormat::DotDecimal, '0.5'];
        yield 'dot: grouped with decimals' => ['1,234.56', NumberFormat::DotDecimal, '1234.56'];
        yield 'dot: space grouping' => ['1 234.5', NumberFormat::DotDecimal, '1234.5'];
        yield 'dot: decimal comma refused' => ['13,5', NumberFormat::DotDecimal, null];
        yield 'comma: comma is decimal' => ['45,123', NumberFormat::CommaDecimal, '45.123'];
        yield 'comma: dot is grouping' => ['13.000', NumberFormat::CommaDecimal, '13000'];
        yield 'comma: grouped with decimals' => ['1.234,56', NumberFormat::CommaDecimal, '1234.56'];
        yield 'comma: space grouping' => ["1\u{202F}234,5", NumberFormat::CommaDecimal, '1234.5'];
        yield 'comma: negative' => ['-3,25', NumberFormat::CommaDecimal, '-3.25'];
        yield 'comma: plain integer' => ['42', NumberFormat::CommaDecimal, '42'];
        yield 'comma: decimal dot refused, not read as canonical' => ['13.5', NumberFormat::CommaDecimal, null];
        yield 'comma: a first group of four digits is not grouping (#581)' => ['48280.320', NumberFormat::CommaDecimal, null];
        yield 'comma: a lone 0 is not a group (#581)' => ['0.500', NumberFormat::CommaDecimal, null];
        yield 'dot: a lone 0 is not a group (#581)' => ['0,500', NumberFormat::DotDecimal, null];
        yield 'dot: a first group of four digits is not grouping' => ['1234,567', NumberFormat::DotDecimal, null];
        yield 'comma: a leading decimal comma reads (K168)' => [',5', NumberFormat::CommaDecimal, '0.5'];
        yield 'comma: a negative leading decimal comma reads (K168)' => ['-,5', NumberFormat::CommaDecimal, '-0.5'];
        yield 'comma: a lone decimal comma is not a number (K168)' => [',', NumberFormat::CommaDecimal, null];
        yield 'comma: a decimal comma with nothing either side is not a number (K168)' => ['-,', NumberFormat::CommaDecimal, null];
        yield 'dot: a leading comma is grouping, not decimal (K168)' => [',5', NumberFormat::DotDecimal, null];
        yield 'comma: three-digit first group reads' => ['123.456,7', NumberFormat::CommaDecimal, '123456.7'];
    }

    #[DataProvider('formatted')]
    public function testParserWithAChosenFormatReadsOnlyThatFormat(string $input, NumberFormat $format, ?string $expected): void
    {
        // The owner's locale plays no part once a format is chosen.
        self::assertSame($expected, DecimalParser::parse($input, 'en_GB', $format));
        self::assertSame($expected, DecimalParser::parse($input, 'de_DE', $format));
    }

    public function testTheLocalesFormatIsItsDecimalSeparator(): void
    {
        self::assertSame(NumberFormat::DotDecimal, NumberFormat::forLocale('en_GB'));
        self::assertSame(NumberFormat::DotDecimal, NumberFormat::forLocale('en_US'));
        self::assertSame(NumberFormat::CommaDecimal, NumberFormat::forLocale('de_DE'));
        self::assertSame(NumberFormat::CommaDecimal, NumberFormat::forLocale('fr_FR'));
    }
}
