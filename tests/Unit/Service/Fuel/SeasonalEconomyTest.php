<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Fuel;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Fuel\MonthlyEconomy;
use Logbook\Service\Fuel\FuelEconomy;
use Logbook\Service\Fuel\SeasonalEconomy;
use PHPUnit\Framework\TestCase;

/**
 * Economy by month (spec.md §7.3): segments split across calendar months
 * in proportion to elapsed time, in the owner's time zone.
 */
final class SeasonalEconomyTest extends TestCase
{
    use BuildsFills;

    public function testASegmentIsSplitInProportionToElapsedTime(): void
    {
        // 20 January to 10 February: 12 days in January, 9 in February.
        $months = $this->months([['2025-01-20 00:00', '2025-02-10 00:00', '420', '30']]);

        $jan = $months->cell(2025, 1);
        $feb = $months->cell(2025, 2);
        self::assertSame(['240.000000', '17.142857'], [$jan?->distanceKm, $jan?->volume]);
        self::assertSame(['180.000000', '12.857143'], [$feb?->distanceKm, $feb?->volume]);
    }

    public function testASegmentOver92DaysIsLeftOut(): void
    {
        $months = $this->months([
            ['2025-01-01 00:00', '2025-04-03 00:00', '3000', '180'],
            ['2025-04-03 00:00', '2025-07-05 00:00', '3000', '180'],
        ]);

        self::assertNotNull($months->cell(2025, 1), '92 days exactly still counts');
        self::assertNull($months->cell(2025, 5), '93 days is too long to place in a season');
        self::assertSame([2025], $months->years());
    }

    public function testMonthBoundariesAreInTheOwnersTimeZone(): void
    {
        // 23:30 UTC on 31 January is already 1 February in Berlin.
        $segments = [['2025-01-31 23:30', '2025-02-10 23:30', '500', '30']];

        self::assertNull($this->months($segments, 'Europe/Berlin')->cell(2025, 1));
        self::assertSame('500', $this->months($segments, 'Europe/Berlin')->cell(2025, 2)?->distanceKm);
        self::assertNotNull($this->months($segments, 'UTC')->cell(2025, 1));
    }

    public function testADstWeekIsSplitByRealElapsedTime(): void
    {
        // Clocks go forward in London on 30 March 2025. From 00:00 UTC on 29
        // March to 00:00 UTC on 2 April is 96 hours; April starts at 23:00
        // UTC on 31 March, so 71 of them are in March.
        $months = $this->months([['2025-03-29 00:00', '2025-04-02 00:00', '960', '60']], 'Europe/London');

        self::assertSame('710.000000', $months->cell(2025, 3)?->distanceKm);
        self::assertSame('250.000000', $months->cell(2025, 4)?->distanceKm);
    }

    public function testASegmentOfNoDurationFallsInItsClosingMonth(): void
    {
        $months = $this->months([['2025-05-31 22:00', '2025-05-31 22:00', '300', '20']], 'Europe/Berlin');

        self::assertSame('300', $months->cell(2025, 6)?->distanceKm);
    }

    public function testAMonthUnder200KmHasNoFigureAndTheAverageIsWeighted(): void
    {
        $months = $this->months([
            ['2024-01-05 00:00', '2024-01-25 00:00', '1000', '50'],
            ['2025-01-05 00:00', '2025-01-10 00:00', '250', '25'],
            ['2025-03-05 00:00', '2025-03-10 00:00', '150', '10'],
        ]);

        self::assertTrue($months->cell(2025, 1)?->hasFigure());
        self::assertFalse($months->cell(2025, 3)?->hasFigure(), '150 km is too little to show');
        // 5 and 10 L/100 km over 1,000 and 250 km: 75 L over 1,250 km (6.0), not 7.5.
        $average = $months->average(1);
        self::assertSame(['1250', '75'], [$average->distanceKm, $average->volume]);
        self::assertTrue($months->hasFigures());
        self::assertSame([2024, 2025], $months->years());
    }

    public function testNoMonthWithAFigureHidesTheCard(): void
    {
        self::assertFalse($this->months([['2025-03-05 00:00', '2025-03-10 00:00', '150', '10']])->hasFigures());
    }

    public function testTheLastFiveYearsAreShownAndTheAverageCoversEveryYear(): void
    {
        $segments = [];
        foreach (range(2019, 2025) as $year) {
            $segments[] = [$year . '-01-05 00:00', $year . '-01-20 00:00', '500', '30'];
        }
        $months = $this->months($segments);

        self::assertSame([2021, 2022, 2023, 2024, 2025], $months->years());
        self::assertSame('3500', $months->average(1)->distanceKm);
        self::assertSame(2026, $months->currentYear);
    }

    public function testChargingIsItsOwnSeries(): void
    {
        $history = FuelEconomy::analyse([
            $this->fillAt('2025-01-05 08:00', '1000', '40', '1.500000', FuelGrade::E10_95),
            $this->fillAt('2025-01-06 08:00', '1100', '20', '0.250000', FuelGrade::Home),
            $this->fillAt('2025-01-09 08:00', '1300', '30', '0.250000', FuelGrade::Home),
            $this->fillAt('2025-01-20 08:00', '1600', '25', '1.500000', FuelGrade::E10_95),
        ]);
        $tz = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('2026-01-01');

        self::assertSame('600', SeasonalEconomy::of($history, EnergyKind::Liquid, $tz, $now)->cell(2025, 1)?->distanceKm);
        self::assertSame('200', SeasonalEconomy::of($history, EnergyKind::Electric, $tz, $now)->cell(2025, 1)?->distanceKm);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $segments [from, to, km, litres] (UTC)
     */
    private function months(array $segments, string $timeZone = 'UTC'): MonthlyEconomy
    {
        $fills = [];
        $km = 10000;
        foreach ($segments as [$from, $to, $distance, $volume]) {
            // Each pair is measured on its own: a missed fill-up before it
            // drops the stretch from the previous pair.
            $fills[] = $this->fillAt($from, (string) $km, '40', '1.500000', missedPrevious: true);
            $km += (int) $distance;
            $fills[] = $this->fillAt($to, (string) $km, $volume, '1.500000');
            $km += 1;
        }

        return SeasonalEconomy::of(
            FuelEconomy::analyse($fills),
            EnergyKind::Liquid,
            new DateTimeZone($timeZone),
            new DateTimeImmutable('2026-03-01 12:00'),
        );
    }
}
