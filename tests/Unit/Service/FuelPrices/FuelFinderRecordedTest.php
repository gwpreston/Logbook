<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\FuelPrices;

use Logbook\Service\FuelPrices\FeedReport;
use Logbook\Service\FuelPrices\Uk\FuelFinderParser;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A trimmed real UK Fuel Finder download, when a maintainer has recorded
 * one with bin/record-fuel-finder.php (decided 2026-10-03, #139): every
 * record parses. Skipped when there is none (the feed needs credentials).
 */
final class FuelFinderRecordedTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../../Fixtures/fuel-finder/';

    public function testEveryRecordedStationAndPriceParses(): void
    {
        if (!is_file(self::DIR . 'recorded-pfs.json') || !is_file(self::DIR . 'recorded-fuel-prices.json')) {
            self::markTestSkipped('No recorded download: run bin/record-fuel-finder.php with credentials.');
        }
        $map = (new ReflectionClass(FuelFinderProvider::class))->newInstanceWithoutConstructor()->gradeMap();
        $stations = json_decode((string) file_get_contents(self::DIR . 'recorded-pfs.json'), true, 64, JSON_THROW_ON_ERROR);
        $prices = json_decode((string) file_get_contents(self::DIR . 'recorded-fuel-prices.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($stations);
        self::assertIsArray($prices);

        $report = new FeedReport();
        self::assertCount(count($stations), FuelFinderParser::stations($stations, $map, $report));
        FuelFinderParser::prices($prices, $map, $report);
        self::assertSame(0, $report->invalid, 'every record has the fields the parser reads');
        self::assertSame(0, $report->unknownGrades, 'every fuel type is in the grade map');
    }
}
