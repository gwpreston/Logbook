<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Kernel;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Import\DateOrder;
use Logbook\Service\Import\ImportField;
use Logbook\Service\Import\ImportVocabulary;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Csv\CsvTooLong;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading an imported file (spec.md §7.13): the CSV itself, dates in the
 * chosen order, and the words files use for choices, yes/no and units.
 */
final class ImportReadingTest extends TestCase
{
    public function testReadsQuotedMultiLineCellsAndSkipsBlankRows(): void
    {
        $csv = CsvReader::read(
            "\xEF\xBB\xBFDate,Notes,\n2026-09-01,\"Two\nlines, and \"\"quotes\"\"\",x\n\n,,\n2026-09-02,,y\n",
            10,
        );

        self::assertNotNull($csv);
        self::assertSame(['Date', 'Notes', '#3'], $csv->header, 'BOM dropped; a blank header gets a name');
        self::assertSame([
            ['line' => 2, 'cells' => ['2026-09-01', "Two\nlines, and \"quotes\"", 'x']],
            ['line' => 5, 'cells' => ['2026-09-02', '', 'y']],
        ], $csv->rows);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function delimiters(): iterable
    {
        yield 'semicolon' => ["Date;Amount\n27/09/2026;1,50\n"];
        yield 'tab' => ["Date\tAmount\n27/09/2026\t1,50\n"];
    }

    #[DataProvider('delimiters')]
    public function testDetectsTheDelimiter(string $contents): void
    {
        $csv = CsvReader::read($contents, 10);

        self::assertNotNull($csv);
        self::assertSame(['Date', 'Amount'], $csv->header);
        self::assertSame(['27/09/2026', '1,50'], $csv->rows[0]['cells']);
    }

    public function testConvertsWindows1252(): void
    {
        $csv = CsvReader::read("Note\nCaf\xE9 \x80 5\n", 10);

        self::assertNotNull($csv);
        self::assertSame('Café € 5', $csv->rows[0]['cells'][0]);
    }

    public function testLimitsAndEmptyFiles(): void
    {
        self::assertNull(CsvReader::read('', 10));
        self::assertNull(CsvReader::read("\n\n", 10));

        $this->expectException(CsvTooLong::class);
        CsvReader::read("A\n1\n2\n3\n", 2);
    }

    public function testDateOrders(): void
    {
        self::assertSame('2026-09-07', DateOrder::Iso->normalise('2026-9-7'));
        self::assertSame('2026-09-27', DateOrder::DayFirst->normalise('27.09.2026'));
        self::assertSame('2026-09-27', DateOrder::MonthFirst->normalise('09/27/2026'));
        self::assertNull(DateOrder::DayFirst->normalise('09/27/2026'), 'no 27th month');
        self::assertNull(DateOrder::DayFirst->normalise('31/02/2026'), 'no 31 February');
        self::assertNull(DateOrder::Iso->normalise('27/09/2026'));
    }

    public function testVocabularyReadsCodesAndLabelsInBothLanguages(): void
    {
        $translator = TranslatorFactory::create(Kernel::rootDir() . '/translations', 'de', null, false);
        $words = new ImportVocabulary($translator, 'de_DE');

        self::assertSame('petrol', $words->choice(Fuel::class, 'fuel.fuel.', 'Benzin'));
        self::assertSame('petrol', $words->choice(Fuel::class, 'fuel.fuel.', 'PETROL'));
        self::assertSame('ev', $words->choice(Fuel::class, 'fuel.fuel.', 'Electricity'));
        self::assertSame('tolls', $words->choice(ExpenseCategory::class, 'expense.category.', 'Maut und Gebühren'));
        self::assertNull($words->choice(ExpenseCategory::class, 'expense.category.', 'Snacks'));

        self::assertTrue($words->flag('ja'));
        self::assertTrue($words->flag('Yes'));
        self::assertTrue($words->flag('1'));
        self::assertFalse($words->flag('nein'));
        self::assertFalse($words->flag('0'));
        self::assertNull($words->flag('maybe'));

        self::assertSame(VolumeUnit::UkGallon, $words->volumeUnit('UK gallons'));
        self::assertSame(VolumeUnit::UkGallon, $words->volumeUnit('Britische Gallonen'));
        self::assertSame(VolumeUnit::Litre, $words->volumeUnit('l'));
        self::assertSame('kwh', $words->volumeUnit('kWh'));
        self::assertNull($words->volumeUnit('barrels'));
        self::assertSame(DistanceUnit::Mile, $words->distanceUnit('Miles'));
        self::assertSame(DistanceUnit::Kilometre, $words->distanceUnit('Kilometer'));

        // Headers: the export's (in either language, unit in brackets ignored), codes and aliases.
        $fields = ImportField::forModule(ExportModule::Fuel);
        $odometer = $fields[1];
        foreach (['Odometer (Miles)', 'Kilometerstand (Kilometer)', 'odometer', 'Mileage'] as $header) {
            self::assertTrue($words->headerMatches($odometer, $header), $header);
        }
        self::assertFalse($words->headerMatches($odometer, 'Price'));
        self::assertSame('Miles', ImportVocabulary::bracketed('Odometer (Miles)'));
    }
}
