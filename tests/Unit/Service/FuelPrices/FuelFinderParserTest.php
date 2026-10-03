<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\FuelPrices;

use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Service\FuelPrices\FeedReport;
use Logbook\Service\FuelPrices\OpeningHours;
use Logbook\Service\FuelPrices\Uk\FuelFinderParser;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Reading UK Fuel Finder's records (spec.md §7.34) from the feed fixture
 * (tests/Fixtures/fuel-finder, synthetic to the published schema, #139):
 * every grade mapped, pounds corrected, implausible prices dropped and
 * counted, closures kept apart.
 */
final class FuelFinderParserTest extends TestCase
{
    public function testStationsAreReadWithTheirGradesPositionsAndClosures(): void
    {
        $report = new FeedReport();
        $stations = FuelFinderParser::stations(self::fixture('pfs-page-1.json'), self::map(), $report);
        $byName = [];
        foreach ($stations as $station) {
            $byName[$station->name] = $station;
        }

        self::assertCount(5, $stations);
        self::assertSame(1, $report->invalid, 'the record without a valid id');

        $tesco = $byName['Tesco Antrim Extra'];
        self::assertSame('Tesco', $tesco->brand, 'upper case is title-cased');
        self::assertSame('BT41 4LD', $tesco->postcode);
        self::assertSame('54.718012', $tesco->latitude);
        self::assertSame('-6.219034', $tesco->longitude);
        self::assertSame([FuelGrade::E10_95, FuelGrade::E5_97, FuelGrade::B7], $tesco->grades);
        self::assertSame('4 Rathenraw Industrial Estate, Antrim, BT41 4LD', $tesco->address, 'the city is already in line 1');
        self::assertSame(['customer_toilets', 'car_wash', 'adblue_pumps'], $tesco->amenities);
        self::assertSame('Mo-Su 06:00-23:00', OpeningHours::text($tesco->openingHours));

        $shell = $byName['Shell Junction One'];
        self::assertSame('Junction One Retail Park, Ballymena Road, Antrim', $shell->address, 'mixed case is kept');
        self::assertContains(FuelGrade::B7Premium, $shell->grades);

        $maxol = $byName['Maxol Antrim'];
        self::assertTrue($maxol->temporarilyClosed);
        self::assertFalse($maxol->permanentlyClosed);
        self::assertSame([FuelGrade::E10_95, FuelGrade::B7, FuelGrade::B10, FuelGrade::Xtl], $maxol->grades, 'HVO is XTL');

        self::assertTrue($byName['Larne Road Service Station']->permanentlyClosed);

        $motorway = $byName['Motorway Services M2'];
        self::assertFalse($motorway->hasPosition(), '0, 0 is no position');
        self::assertSame([FuelGrade::E10_95, FuelGrade::B7], $motorway->grades, 'an unknown code is left out');
        self::assertSame('M2 Northbound, Templepatrick', $motorway->address, 'a road number keeps its case');
    }

    public function testPricesAreMappedCorrectedAndChecked(): void
    {
        $report = new FeedReport();
        $prices = FuelFinderParser::prices(self::fixture('fuel-prices-page-1.json'), self::map(), $report);
        $got = array_map(
            static fn (FeedPrice $p): string => sprintf(
                '%s %s %s %s',
                substr($p->ref, 0, 6),
                $p->grade->value,
                $p->price,
                $p->reportedAt->format('Y-m-d H:i'),
            ),
            $prices,
        );

        self::assertSame([
            self::short('antrim-tesco') . ' e10_95 1.359 2026-10-03 06:15',
            self::short('antrim-tesco') . ' e5_97 1.499 2026-10-03 06:15',
            self::short('antrim-tesco') . ' b7 1.429 2026-10-02 18:40',
            self::short('antrim-shell') . ' e10_95 1.379 2026-10-03 05:00',
            self::short('antrim-shell') . ' b7 1.459 2026-10-03 05:00',
            self::short('antrim-shell') . ' b7_premium 1.599 2026-10-03 05:00',
            self::short('antrim-maxol') . ' e10_95 1.339 2026-09-29 08:00',
            self::short('antrim-maxol') . ' b7 1.419 2026-10-03 04:00',
            self::short('antrim-maxol') . ' b10 1.399 2026-10-03 04:00',
            self::short('antrim-maxol') . ' xtl 1.699 2026-10-03 04:00',
        ], $got);
        self::assertSame(1, $report->corrected, '1.379 was typed in pounds');
        self::assertSame(2, $report->implausible, '12p and 999.9p');
        self::assertSame(1, $report->unknownGrades);
        self::assertSame('UTC', $prices[0]->reportedAt->getTimezone()->getName(), 'times without a zone are UTC');
    }

    public function testTheE5MappingIsTheAdminsChoice(): void
    {
        $provider = self::provider();
        self::assertSame(FuelGrade::E5_97, $provider->gradeMap()['E5']);
        self::assertSame(FuelGrade::E5_99, $provider->gradeMap(['E5' => FuelGrade::E5_99])['E5']);
        self::assertSame(FuelGrade::E5_97, $provider->gradeMap(['E5' => FuelGrade::B7])['E5'], 'not an E5 grade');
        self::assertSame([
            'E10' => FuelGrade::E10_95,
            'B7_STANDARD' => FuelGrade::B7,
            'B7_PREMIUM' => FuelGrade::B7Premium,
            'B10' => FuelGrade::B10,
            'HVO' => FuelGrade::Xtl,
        ], array_intersect_key($provider->gradeMap(), array_flip(['E10', 'B7_STANDARD', 'B7_PREMIUM', 'B10', 'HVO'])));
    }

    /**
     * @return iterable<string, array{mixed, string|null, int, int}>
     */
    public static function prices(): iterable
    {
        yield 'pence' => ['0135.9000', '1.359', 0, 0];
        yield 'pence, no padding' => ['135.95', '1.360', 0, 0];
        yield 'pounds' => ['1.379', '1.379', 1, 0];
        yield 'lowest plausible' => ['50', '0.500', 0, 0];
        yield 'highest plausible' => ['500', '5.000', 0, 0];
        yield 'too low' => ['0049.9000', null, 0, 1];
        yield 'too high' => ['0500.1000', null, 0, 1];
        yield 'zero' => ['0', null, 0, 1];
        yield 'a number' => [142.9, '1.429', 0, 0];
    }

    #[DataProvider('prices')]
    public function testPriceRules(mixed $raw, ?string $expected, int $corrected, int $implausible): void
    {
        $report = new FeedReport();
        self::assertSame($expected, FuelFinderParser::price($raw, $report));
        self::assertSame($corrected, $report->corrected);
        self::assertSame($implausible, $report->implausible);
    }

    public function testTimesWithAZoneAreConverted(): void
    {
        self::assertEquals(new DateTimeImmutable('2026-10-03T05:00:00Z'), FuelFinderParser::time('2026-10-03T06:00:00+01:00'));
        self::assertNull(FuelFinderParser::time('yesterday'));
        self::assertNull(FuelFinderParser::time(null));
    }

    /**
     * @return list<mixed>
     */
    public static function fixture(string $file): array
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/fuel-finder/' . $file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($data);

        return array_values($data);
    }

    /**
     * @return array<string, FuelGrade>
     */
    private static function map(): array
    {
        return self::provider()->gradeMap();
    }

    private static function provider(): FuelFinderProvider
    {
        return (new ReflectionClass(FuelFinderProvider::class))->newInstanceWithoutConstructor();
    }

    private static function short(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 6);
    }
}
