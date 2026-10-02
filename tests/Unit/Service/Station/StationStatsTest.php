<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Station;

use DateTimeImmutable;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\Station\GradeStats;
use Logbook\Service\Station\StationStats;
use Logbook\Service\Station\StationVisit;
use Logbook\Support\Number\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * What a user paid at a station (spec.md §7.33): averages weighted by
 * volume, the cheapest, per grade and currency, and only the amounts the
 * user may see.
 */
final class StationStatsTest extends TestCase
{
    private static int $id = 0;

    private static function visit(
        int $station,
        string $at,
        string $litres,
        string $price,
        ?FuelGrade $grade = FuelGrade::E10_95,
        string $currency = 'GBP',
        bool $visible = true,
        int $vehicle = 1,
    ): StationVisit {
        $data = new FuelEntryData(
            new DateTimeImmutable($at),
            '1000.000',
            $grade?->family() ?? Fuel::Petrol,
            $litres,
            $price,
            Decimal::multiply($litres, $price, 3),
            grade: $grade,
            stationId: $station,
            station: 'Station ' . $station,
        );
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

        return new StationVisit(new FuelEntry(++self::$id, $vehicle, $data, $now, $now), $currency, $visible);
    }

    public function testTheAverageIsWeightedByVolumeAndTheCheapestFound(): void
    {
        $summary = StationStats::summarise([
            self::visit(7, '2026-01-10T08:00:00Z', '10.000', '1.500000'),
            self::visit(7, '2026-02-10T08:00:00Z', '40.000', '1.400000'),
            self::visit(7, '2026-03-10T08:00:00Z', '30.000', '1.450000'),
        ])[7];

        self::assertSame(3, $summary->visits);
        self::assertEquals(new DateTimeImmutable('2026-03-10T08:00:00Z'), $summary->lastVisit);
        $grade = $summary->mainGrade();
        self::assertNotNull($grade);
        // (10 × 1.5 + 40 × 1.4 + 30 × 1.45) / 80 = 114.5 / 80 = 1.43125, not the plain mean 1.45.
        self::assertSame('1.431250', $grade->averagePrice);
        self::assertSame('1.400000', $grade->cheapestPrice);
        self::assertEquals(new DateTimeImmutable('2026-02-10T08:00:00Z'), $grade->cheapestOn);
        self::assertSame('1.450000', $grade->lastPrice);
        self::assertSame('80.000', $grade->volume);
        self::assertSame('114.500', $grade->spend);
        self::assertCount(3, $summary->history);
    }

    public function testEachGradeAndCurrencyStandsAlone(): void
    {
        $summaries = StationStats::summarise([
            self::visit(1, '2026-01-10T08:00:00Z', '40.000', '1.400000'),
            self::visit(1, '2026-01-12T08:00:00Z', '10.000', '1.700000', FuelGrade::E5_97),
            self::visit(1, '2026-01-14T08:00:00Z', '5.000', '1.900000', null),
            self::visit(1, '2026-01-15T08:00:00Z', '45.000', '1.800000', currency: 'EUR'),
            self::visit(2, '2026-01-16T08:00:00Z', '20.000', '1.300000'),
        ]);

        self::assertCount(2, $summaries);
        $grades = $summaries[1]->grades;
        self::assertCount(4, $grades);
        // Most volume first.
        self::assertSame(['petrol:e10_95', 'petrol:e10_95', 'petrol:e5_97', 'petrol'], array_map(static fn (GradeStats $g): string => $g->key(), $grades));
        self::assertSame(['EUR', 'GBP'], [$grades[0]->currency, $grades[1]->currency]);
        self::assertSame('1.400000', $summaries[1]->grade('petrol:e10_95', 'GBP')?->averagePrice);
        self::assertSame('1.700000', $summaries[1]->grade('petrol:e5_97')?->averagePrice);
        $spend = $summaries[1]->spend();
        ksort($spend);
        // GBP: 40 × 1.40 + 10 × 1.70 + 5 × 1.90 = 56 + 17 + 9.5.
        self::assertSame(['EUR' => '81.000', 'GBP' => '82.500'], $spend);
    }

    public function testHiddenAmountsCountAsVisitsOnly(): void
    {
        $summary = StationStats::summarise([
            self::visit(3, '2026-01-10T08:00:00Z', '40.000', '1.400000'),
            self::visit(3, '2026-01-20T08:00:00Z', '40.000', '1.200000', visible: false),
        ])[3];

        self::assertSame(2, $summary->visits);
        self::assertEquals(new DateTimeImmutable('2026-01-20T08:00:00Z'), $summary->lastVisit);
        $grade = $summary->mainGrade();
        self::assertNotNull($grade);
        self::assertSame(2, $grade->visits);
        self::assertSame('1.400000', $grade->averagePrice, 'the hidden price is not averaged');
        self::assertSame('1.400000', $grade->cheapestPrice);
        self::assertSame('56.000', $grade->spend);
        self::assertCount(1, $summary->history);
    }

    public function testOnlyFillUpsSinceAnInstantWhenAsked(): void
    {
        $visits = [
            self::visit(4, '2025-01-10T08:00:00Z', '40.000', '1.600000'),
            self::visit(4, '2026-01-10T08:00:00Z', '40.000', '1.400000'),
        ];
        self::assertSame(2, StationStats::summarise($visits)[4]->visits);
        $recent = StationStats::summarise($visits, new DateTimeImmutable('2025-06-01T00:00:00Z'))[4];
        self::assertSame(1, $recent->visits);
        self::assertSame('1.400000', $recent->mainGrade()?->averagePrice);
        self::assertSame([], StationStats::summarise($visits, new DateTimeImmutable('2027-01-01T00:00:00Z')));
    }
}
