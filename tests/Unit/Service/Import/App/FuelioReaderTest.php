<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import\App;

use Logbook\Kernel;
use Logbook\Service\Import\App\Fuelio\FuelioExport;
use Logbook\Service\Import\App\Fuelio\FuelioReader;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Support\Csv\CsvTooLong;
use Logbook\Tests\Support\FuelioCsv;
use PHPUnit\Framework\TestCase;

/**
 * Splitting a Fuelio export into its sections and reading them (spec.md
 * §7.13 *Fuelio's format*), against the owner's anonymised export (the
 * expected figures are in tests/Fixtures/import/fuelio/expected.json).
 */
final class FuelioReaderTest extends TestCase
{
    public function testTheOwnersExportIsDetectedAndReadSectionBySection(): void
    {
        $export = self::fixture();
        $expected = self::expected();

        self::assertSame($expected['fills'], count($export->fills));
        self::assertSame($expected['costs'], count($export->costs));
        self::assertSame($expected['categories'], count($export->categories));
        self::assertSame($expected['stations'], count($export->stations));
        self::assertSame($expected['photos'], count($export->photos));
        self::assertSame(['Category'], $export->unread, 'trip categories are not read');
        self::assertSame(['mi', 'litres', 'mpg'], [$export->distanceText, $export->volumeText, $export->consumptionText]);
        self::assertSame([110], $export->fuelCodes());
        self::assertSame([1, 2, 4, 5, 6, 7, 8, 9, 31], $export->categoryIds());

        // The newest fill-up first, as Fuelio writes them.
        $newest = $export->fills[0];
        self::assertSame($expected['newest_fill'], [
            'date' => $newest->date,
            'odometer' => $newest->odometer,
            'volume' => $newest->volume,
            'total' => $newest->total,
            'price' => $newest->pricePerUnit,
            'station' => $newest->stationName(),
            'unique_id' => $newest->uniqueId,
        ]);
        self::assertTrue($newest->full);
        self::assertSame('', $newest->ownEconomy, 'the latest tank is still open');
        self::assertNotNull($newest->position());

        $partial = array_values(array_filter($export->fills, static fn ($f): bool => !$f->full));
        self::assertCount($expected['partial_fills'], $partial);

        $vehicle = $export->vehicle;
        self::assertSame(['Audi', 'RS3', 2026, 'AB12 CDE', 'yyyy-MM-dd', 100, '50.0'], [
            $vehicle->make,
            $vehicle->model,
            $vehicle->year,
            $vehicle->plate,
            $vehicle->dateFormat,
            $vehicle->tank1Type,
            $vehicle->tank1Capacity,
        ]);

        $station = $export->station($newest->stationId);
        self::assertNotNull($station, 'a fill-up names its favourite station by id');
        self::assertSame('GBR', $station->countryCode);

        $cost = $export->costs[0];
        self::assertSame(['1st Oil Service', 1, '220.0', false, false, false], [
            $cost->title, $cost->typeId, $cost->cost, $cost->isIncome, $cost->isTemplate, $cost->repeats(),
        ]);
        self::assertSame(1, $export->photos[0]->type);
    }

    public function testTheBackupsCsvIsTheSameExportWithTheSameRowIds(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::dir() . '/backup.fuelio.zip'));
        $inner = (string) $zip->getFromName('vehicle-1-local.csv');
        $zip->close();
        $file = SectionSplitter::split('vehicle-1-local.csv', $inner);
        self::assertNotNull($file);
        $backup = FuelioReader::read($file);

        $csv = self::fixture();
        self::assertSame(
            array_map(static fn ($f): string => $f->guid, $csv->fills),
            array_map(static fn ($f): string => $f->guid, $backup->fills),
            'row ids are stable between exports',
        );
    }

    public function testAnythingElseIsNotFuelio(): void
    {
        self::assertNull(SectionSplitter::split('a.csv', "Date,Odometer\n2026-01-01,100\n"), 'no sections at all');
        $other = SectionSplitter::split('b.csv', "\"## Trips\"\n\"From\",\"To\"\n\"A\",\"B\"\n");
        self::assertNotNull($other);
        self::assertFalse(FuelioReader::detect($other));
    }

    public function testValuesAreReadAsCsvWhateverTheEncoding(): void
    {
        $csv = (new FuelioCsv())
            ->fill('2026-01-02 08:00', '1000', '40', '60', '1.5', ['Notes' => "two\nlines, \"quoted\""])
            ->build();
        // A BOM and Windows-1252 (an é in the car's name).
        $text = "\xEF\xBB\xBF" . str_replace('Test car', 'Caf' . "\xE9", $csv);
        $file = SectionSplitter::split('x.csv', $text);
        self::assertNotNull($file);
        $export = FuelioReader::read($file);

        self::assertSame('Café', $export->vehicle->name);
        self::assertSame("two\nlines, \"quoted\"", $export->fills[0]->notes);
        self::assertSame(['km', 'litres', 'l/100km'], [$export->distanceText, $export->volumeText, $export->consumptionText]);
    }

    public function testRowsBeyondTheLimitAreRefused(): void
    {
        $csv = new FuelioCsv();
        for ($i = 0; $i < 6; $i++) {
            $csv->fill('2026-01-0' . ($i + 1) . ' 08:00', (string) (1000 + 100 * $i), '40', '60', '1.5');
        }

        $this->expectException(CsvTooLong::class);
        SectionSplitter::split('x.csv', $csv->build(), 15);
    }

    public static function fixture(): FuelioExport
    {
        $file = SectionSplitter::split('export.csv', (string) file_get_contents(self::dir() . '/export.csv'));
        self::assertNotNull($file);
        self::assertTrue(FuelioReader::detect($file));

        return FuelioReader::read($file);
    }

    /**
     * @return array{
     *     fills: int, partial_fills: int, costs: int, categories: int, stations: int, photos: int,
     *     total_volume_litres: string, total_cost: string, measured_tanks: int, economy_check_flags: int,
     *     newest_fill: array<string, string>, fuelio_mpg_by_opening_odometer: array<string, string>,
     *     photos_by_fill_unique_id: array<int, int>
     * }
     */
    public static function expected(): array
    {
        $json = json_decode((string) file_get_contents(self::dir() . '/expected.json'), true);
        self::assertIsArray($json);
        /** @var array{
         *     fills: int, partial_fills: int, costs: int, categories: int, stations: int, photos: int,
         *     total_volume_litres: string, total_cost: string, measured_tanks: int, economy_check_flags: int,
         *     newest_fill: array<string, string>, fuelio_mpg_by_opening_odometer: array<string, string>,
         *     photos_by_fill_unique_id: array<int, int>
         * } $json */
        return $json;
    }

    private static function dir(): string
    {
        return Kernel::rootDir() . '/tests/Fixtures/import/fuelio';
    }
}
