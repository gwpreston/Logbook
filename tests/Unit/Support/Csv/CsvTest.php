<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Csv;

use Logbook\Support\Csv\CsvNumber;
use Logbook\Support\Csv\CsvTable;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testDocumentsAreUtf8WithABomAndCrlfLines(): void
    {
        $csv = CsvWriter::document(['Date', 'Note'], [['2026-09-27', 'Café, “Cheap” fuel'], ['2026-09-28', null]]);

        self::assertStringStartsWith("\u{FEFF}Date,Note\r\n", $csv);
        self::assertStringContainsString("2026-09-27,\"Café, “Cheap” fuel\"\r\n", $csv);
        self::assertStringEndsWith("2026-09-28,\r\n", $csv);
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }

    public function testQuotingFollowsRfc4180(): void
    {
        self::assertSame('plain', CsvWriter::cell('plain'));
        self::assertSame('"say ""hi"""', CsvWriter::cell('say "hi"'));
        self::assertSame("\"two\nlines\"", CsvWriter::cell("two\nlines"));
        self::assertSame('"a,b"', CsvWriter::cell('a,b'));
        self::assertSame('', CsvWriter::cell(null));
    }

    public function testTextThatWouldRunAsAFormulaIsDefused(): void
    {
        self::assertSame('"\'=HYPERLINK(""x"")"', CsvWriter::cell('=HYPERLINK("x")'));
        self::assertSame("'+44 20 7946 0000", CsvWriter::cell('+44 20 7946 0000'));
        self::assertSame("'@SUM(A1)", CsvWriter::cell('@SUM(A1)'));
        self::assertSame("'-rf", CsvWriter::cell('-rf'));
        // Numbers are not text: a negative number stays a number.
        self::assertSame('-12.5', CsvWriter::cell('-12.5'));
        self::assertSame('45.20', CsvWriter::cell('45.20'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function amounts(): iterable
    {
        yield 'pounds keep their pence' => ['45.200', 'GBP', '45.20'];
        yield 'zero is 0.00' => ['0.000', 'GBP', '0.00'];
        yield 'extra places are kept' => ['45.125', 'GBP', '45.125'];
        yield 'yen have none' => ['1000.000', 'JPY', '1000'];
        yield 'dinars have three' => ['12.500', 'BHD', '12.500'];
    }

    #[DataProvider('amounts')]
    public function testAmountsKeepTheCurrencysPlaces(string $stored, string $currency, string $expected): void
    {
        self::assertSame($expected, CsvNumber::money($stored, $currency));
    }

    public function testConvertedQuantitiesConvertBackToTheStoredValue(): void
    {
        foreach (['48280.320', '0.000', '12345.678', '1.609'] as $km) {
            $miles = CsvNumber::distance($km, DistanceUnit::Mile);
            self::assertSame($km, DistanceUnit::Mile->toKmDecimal($miles, 3), $km . ' km as ' . $miles . ' mi');
        }
        self::assertSame('30000', CsvNumber::distance('48280.320', DistanceUnit::Mile));
        self::assertSame('48280.32', CsvNumber::distance('48280.320', DistanceUnit::Kilometre));

        foreach ([VolumeUnit::UkGallon, VolumeUnit::UsGallon, VolumeUnit::Litre] as $unit) {
            foreach (['45.678', '0.001', '60.000'] as $litres) {
                $value = CsvNumber::volume($litres, $unit, false);
                $message = sprintf('%s L as %s %s', $litres, $value, $unit->value);
                self::assertSame($litres, $unit->toLitresDecimal($value, 3), $message);
            }
            foreach (['1.459000', '0.000000', '1.987654'] as $perLitre) {
                $price = CsvNumber::unitPrice($perLitre, $unit, false);
                $message = sprintf('%s/L as %s/%s', $perLitre, $price, $unit->value);
                self::assertSame($perLitre, $unit->pricePerLitre($price, 6), $message);
            }
        }

        self::assertSame('52.3', CsvNumber::volume('52.300', VolumeUnit::UkGallon, true), 'kWh are not converted');
        self::assertSame('0.245', CsvNumber::unitPrice('0.245000', VolumeUnit::UsGallon, true));
    }

    public function testFileNameSlugs(): void
    {
        self::assertSame('pat-s-golf-gti', CsvTable::slug("Pat's Golf GTI", 'vehicle-1'));
        self::assertSame('skoda-octavia', CsvTable::slug('Škoda Octavia', 'vehicle-1'));
        self::assertSame('vehicle-9', CsvTable::slug('***', 'vehicle-9'), 'nothing usable: the fallback');
    }
}
