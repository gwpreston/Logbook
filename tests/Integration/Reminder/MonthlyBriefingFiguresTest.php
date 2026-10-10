<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\Digest\DigestSummary;
use Logbook\Service\Notification\Digest\LastMonth;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\BriefingTestCase;
use Logbook\Tests\Support\Migrator;

/**
 * The monthly briefing's *Last month* figures (spec.md §7.11 *The monthly
 * briefing*, Phase 43.3): equal to the Reports page's, averages that skip
 * empty months or are left out, the 100 km floor and currencies kept apart.
 * "Today" is 1 Oct 2026 in London, so last month is September 2026.
 */
final class MonthlyBriefingFiguresTest extends BriefingTestCase
{
    /** Sep 2025 to Aug 2026, the 12 months before last month. */
    private const array WINDOW = [
        '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02',
        '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08',
    ];

    public function testLastMonthEqualsTheReportsPageForThatMonth(): void
    {
        $this->start();
        $golf = $this->car();
        $this->monthlyReadings($golf, ['2025-08', ...self::WINDOW, '2026-09'], 10000, 913);
        foreach ([...self::WINDOW, '2026-09'] as $i => $month) {
            $this->spend($golf, $month . '-12', (string) (40 + $i * 13) . '.125');
        }

        $line = $this->lastMonth($golf)->lines[0];
        $report = $this->reportFor($golf, ReportPeriod::month(self::date('2026-09-01')));
        $section = $report->currencies[0];

        self::assertNotNull($line->costs);
        self::assertSame($section->distanceKm, $line->distanceKm);
        self::assertSame($section->total->toDecimal(3), $line->costs->spend->toDecimal(3));
        self::assertSame($section->costPerKm, $line->costs->costPerKm);
        self::assertNotNull($line->costs->costPerKm, 'a 913 km month has a cost per distance');

        $twelve = new ReportPeriod(ReportRange::Custom, self::date('2025-09-01'), self::date('2026-08-31'));
        $year = $this->reportFor($golf, $twelve);
        self::assertSame($year->currencies[0]->costPerKm, $line->costs->costPerKmAverage);

        // And the webhook carries the same figures.
        $this->runTasks();
        $row = self::row($this->digestJson());
        self::assertSame(0, Decimal::compare(self::text($row['distance']), (string) $section->distanceKm));
        self::assertSame(0, Decimal::compare(self::text($row['spend']), $section->total->toDecimal(3)));
        self::assertSame('GBP', $row['currency']);
        self::assertSame('2026-09', $row['month']);
        self::assertSame($golf->id, $row['vehicle_id']);
    }

    public function testEveryDemoVehicleMatchesTheReportsPage(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-05T10:00:00Z');
        $this->resetDatabase($app);
        Migrator::run('seed:run', ['--seed' => ['DemoDataSeeder']]);
        $demo = $this->service($app, UserRepository::class)->findByUsername('demo');
        self::assertInstanceOf(User::class, $demo);
        $vehicles = $this->service($app, VehicleService::class)->listFleet($demo, true);
        self::assertNotSame([], $vehicles);
        $summary = $this->service($app, DigestSummary::class);
        $reports = $this->service($app, ReportService::class);
        $month = ReportPeriod::month(self::date('2026-09-01'));

        $listed = 0;
        foreach ($vehicles as $vehicle) {
            $lastMonth = $summary->lastMonth($demo, [$vehicle], self::date('2026-10-01'));
            $report = $reports->build($demo, new ReportFilter($month, $vehicle->id));
            $section = $report->currencies[0];
            if ($lastMonth === null) {
                self::assertTrue($section->total->isZero(), $vehicle->name() . ' spent nothing');
                continue;
            }
            $listed++;
            $line = $lastMonth->lines[0];
            self::assertNotNull($line->costs, $vehicle->name());
            self::assertSame(
                0,
                Decimal::compare($section->total->toDecimal(3), $line->costs->spend->toDecimal(3)),
                $vehicle->name() . ' spend',
            );
            if ($section->distanceKm !== null && Decimal::compare($section->distanceKm, '100') >= 0) {
                self::assertSame($section->costPerKm, $line->costs->costPerKm, $vehicle->name() . ' cost per distance');
                self::assertSame($section->distanceKm, $line->distanceKm, $vehicle->name() . ' distance');
            } else {
                self::assertNull($line->costs->costPerKm, $vehicle->name() . ' under 100 km');
            }
        }
        self::assertGreaterThan(0, $listed, 'the sample data has a month to report');
    }

    public function testMonthsWithNoMeasurableDistanceAreLeftOutOfTheDistanceAverage(): void
    {
        $this->start();
        $golf = $this->car();
        // Mar, Apr, May, Jun, then nothing in Jul and Aug, then Sep.
        $this->reading($golf, '10000', '2026-03-10');
        $this->reading($golf, '10500', '2026-04-10');
        $this->reading($golf, '11200', '2026-05-10');
        $this->reading($golf, '11800', '2026-06-10');
        $this->reading($golf, '13000', '2026-09-10');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('1200.000', $row['distance'], 'Jun to Sep');
        self::assertSame('600.000', $row['distance_average'], '(500 + 700 + 600) / 3: Jul and Aug are not zeros');
        $text = $this->digestText();
        self::assertStringContainsString(
            '• Volkswagen Golf: 746 mi driven, about 100% more than your monthly average (373 mi)',
            $text,
        );
    }

    public function testFewerThanThreeMeasuredMonthsLeavesTheDistanceComparisonOut(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '10500', '2026-04-10');
        $this->reading($golf, '11200', '2026-05-10');
        $this->reading($golf, '11800', '2026-06-10');
        $this->reading($golf, '13000', '2026-09-10');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('1200.000', $row['distance']);
        self::assertNull($row['distance_average'], 'only May and Jun were measured');
        $text = $this->digestText();
        self::assertStringContainsString('• Volkswagen Golf: 746 mi driven', $text);
        self::assertStringNotContainsString('monthly average', $text);
    }

    public function testTheSpendAverageDividesByMonthsSinceTheFirstLedgerLine(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-06-05', '100');
        $this->spend($golf, '2026-08-05', '200');
        $this->spend($golf, '2026-09-05', '60');
        $this->spend($golf, '2026-09-06', '50');
        $this->spend($golf, '2026-09-07', '40');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('150.000', $row['spend']);
        self::assertSame('100.000', $row['spend_average'], '(100 + 0 + 200) / 3 months in use, not / 12');
        self::assertStringContainsString(
            '• Volkswagen Golf: £150.00 spent, about 50% more than your monthly average (£100.00)',
            $this->digestText(),
        );
    }

    public function testFewerThanThreeMonthsInUseLeavesTheSpendComparisonOut(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-07-05', '100');
        $this->spend($golf, '2026-09-05', '60');
        $this->spend($golf, '2026-09-06', '50');
        $this->spend($golf, '2026-09-07', '40');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertNull($row['spend_average'], 'Jul and Aug only');
        $text = $this->digestText();
        self::assertStringContainsString('• Volkswagen Golf: £150.00 spent', $text);
        self::assertStringNotContainsString('monthly average', $text);
    }

    public function testTheSpendAverageCountsAtMostTwelveMonthsFromTheFirstReading(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '1000', '2024-01-10');
        $this->spend($golf, '2026-08-05', '120');
        $this->spend($golf, '2026-09-05', '60');
        $this->spend($golf, '2026-09-06', '50');
        $this->spend($golf, '2026-09-07', '40');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('10.000', $row['spend_average'], '120 over 12 months');
    }

    public function testAMonthUnderOneHundredKilometresShowsADashForCostPerDistance(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '9000', '2026-01-10');
        $this->reading($golf, '10000', '2026-08-20');
        $this->reading($golf, '10050', '2026-09-10');
        $this->spend($golf, '2026-03-05', '90');
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('50.000', $row['distance']);
        self::assertNull($row['cost_per_distance']);
        self::assertIsString($row['cost_per_distance_average'], 'the 12 months drove 1,000 km');
        self::assertStringContainsString('• Volkswagen Golf: running cost —', $this->digestText());
    }

    public function testTheTwelveMonthCostPerDistanceNeedsAHundredKilometresToo(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '10000', '2026-08-20');
        $this->reading($golf, '10090', '2026-09-10');
        $this->spend($golf, '2026-08-25', '90');
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('90.000', $row['distance']);
        self::assertNull($row['cost_per_distance']);
        self::assertNull($row['cost_per_distance_average']);
    }

    public function testACostPerDistanceFromAHundredKilometresIsShown(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '10000', '2026-08-20');
        $this->reading($golf, '10100', '2026-09-10');
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('100.000', $row['distance'], 'exactly 100 km counts');
        self::assertSame('0.3000', $row['cost_per_distance']);
        self::assertStringNotContainsString('running cost —', $this->digestText());
    }

    public function testCurrenciesAreNeverMixedInTheFleetLine(): void
    {
        $this->start();
        $golf = $this->car();
        $polo = $this->car('Polo', 'EUR');
        foreach ([$golf, $polo] as $vehicle) {
            $this->reading($vehicle, '10000', '2026-08-20');
            $this->reading($vehicle, '10400', '2026-09-10');
        }
        $this->spend($golf, '2026-09-05', '30');
        $this->spend($polo, '2026-09-06', '20');

        $this->runTasks();

        $fleet = $this->digestJson()['fleet'] ?? null;
        self::assertIsArray($fleet);
        self::assertSame('800.000', $fleet['distance']);
        self::assertSame(['GBP' => '30.000', 'EUR' => '20.000'], $fleet['spend'], 'a key per currency, never summed');
        $text = $this->digestText();
        self::assertStringContainsString('• All vehicles: 497 mi driven; £30.00, €20.00 spent', $text);
        self::assertStringContainsString('• Volkswagen Polo: €20.00 spent', $text);
    }

    public function testASingleVehicleHasNoFleetLine(): void
    {
        $this->start();
        $golf = $this->car();
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $json = $this->digestJson();
        self::assertArrayHasKey('fleet', $json);
        self::assertNull($json['fleet']);
        self::assertStringNotContainsString('All vehicles', $this->digestText());
    }

    public function testAVehicleWithNothingInTheMonthIsNotListed(): void
    {
        $this->start();
        $golf = $this->car();
        $this->car('Polo');
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $rows = $this->digestJson()['last_month'] ?? null;
        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('Polo', $this->digestText());
    }

    public function testAnArchivedVehicleIsLeftOut(): void
    {
        $this->start();
        $golf = $this->car();
        $polo = $this->car('Polo');
        $this->spend($golf, '2026-09-05', '30');
        $this->spend($polo, '2026-09-05', '40');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $polo);

        $this->runTasks();

        self::assertStringNotContainsString('Polo', $this->digestText());
    }

    public function testTheLargeEntryIsNamedOnlyAboveHalfTheMonth(): void
    {
        $this->start();
        $golf = $this->car();
        $this->insurance($golf, '2026-09-05', '412');
        $this->spend($golf, '2026-09-08', '192');

        $this->runTasks();

        self::assertStringContainsString('£604.00 spent, including Insurance £412.00', $this->digestText());
    }

    public function testAnEntryOfExactlyHalfIsNotNamed(): void
    {
        $this->start();
        $golf = $this->car();
        $this->insurance($golf, '2026-09-05', '300');
        $this->spend($golf, '2026-09-08', '300');

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('£600.00 spent', $text);
        self::assertStringNotContainsString('including', $text);
    }

    public function testAnEntryBelowHalfIsNotNamed(): void
    {
        $this->start();
        $golf = $this->car();
        $this->insurance($golf, '2026-09-05', '200');
        $this->spend($golf, '2026-09-08', '250');
        $this->spend($golf, '2026-09-09', '150', ExpenseCategory::Tolls);

        $this->runTasks();

        self::assertStringNotContainsString('including', $this->digestText());
    }

    public function testAReadingOnTheLocalFirstOfOctoberCountsInOctoberNotSeptember(): void
    {
        $this->start();
        $golf = $this->car();
        $this->reading($golf, '10000', '2026-08-20');
        $this->reading($golf, '10300', '2026-09-20');
        // 23:30 UTC on 30 Sep is 00:30 on 1 Oct in London: not September's.
        $this->reading($golf, '10900', '2026-09-30', '23:30:00');
        $this->spend($golf, '2026-09-05', '30');

        $this->runTasks();

        $row = self::row($this->digestJson());
        self::assertSame('300.000', $row['distance']);
        $line = $this->lastMonth($golf)->lines[0];
        $report = $this->reportFor($golf, ReportPeriod::month(self::date('2026-09-01')));
        self::assertSame($report->currencies[0]->distanceKm, $line->distanceKm);
    }

    private function lastMonth(Vehicle $vehicle): LastMonth
    {
        $lastMonth = $this->service($this->app, DigestSummary::class)
            ->lastMonth($this->owner($this->app), [$vehicle], self::date('2026-10-01'));
        self::assertNotNull($lastMonth);

        return $lastMonth;
    }

    private function reportFor(Vehicle $vehicle, ReportPeriod $period): \Logbook\Service\Report\Report
    {
        return $this->service($this->app, ReportService::class)
            ->build($this->owner($this->app), new ReportFilter($period, $vehicle->id));
    }
}
