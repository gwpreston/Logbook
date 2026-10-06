<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Dashboard\Dashboard;
use Logbook\Service\Dashboard\DashboardLayoutStore;
use Logbook\Service\Dashboard\DashboardService;
use Logbook\Service\Dashboard\ExpenseBreakdown;
use Logbook\Service\Dashboard\ExpensePeriod;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Report\Report;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCostRange;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The Expense breakdown and Monthly spend widgets (spec.md §7.8, Phase
 * 34.2): Reports' own figures for the vehicles in view whose costs the
 * viewer may see, per currency, with links into Reports. "Today" is 27 Sep
 * 2026 in Europe/London.
 */
final class SpendWidgetsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const string TODAY = '2026-09-27';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;

    public function testTheBreakdownIsReportsGroupByGroupForEachPeriod(): void
    {
        $this->start();
        $this->costs($this->vehicle($this->app));

        foreach (ExpensePeriod::cases() as $period) {
            $breakdown = $this->dashboard(null, $period)->expenseBreakdown;
            self::assertNotNull($breakdown, $period->value);
            self::assertFalse($breakdown->isEmpty(), $period->value);
            self::assertSameAsReport($this->report($period->range()), $breakdown);
        }
        // The periods differ: last year's November fill-up is in the 12 months only.
        self::assertNotEquals(
            $this->dashboard(null, ExpensePeriod::TwelveMonths)->expenseBreakdown?->sections[0]->total,
            $this->dashboard(null, ExpensePeriod::ThisYear)->expenseBreakdown?->sections[0]->total,
        );

        $section = $this->dashboard(null, ExpensePeriod::DEFAULT)->expenseBreakdown?->sections[0];
        self::assertNotNull($section);
        self::assertSame(100, array_sum(array_map(static fn ($row): int => $row->percent, $section->rows)));
        self::assertSame(['fuel', 'maintenance', 'compliance', 'other'], array_map(
            static fn ($row): string => $row->group->value,
            $section->rows,
        ), "Reports' order");
    }

    public function testTheBreakdownWidgetLinksIntoReportsAndKeepsItsPeriodInTheUrl(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->costs($golf);

        $widget = self::widget(self::body($this->browser->get('/')), 'expense_breakdown');
        self::assertStringContainsString('class="stack-bar"', $widget, 'the bar, drawn without JS');
        self::assertMatchesRegularExpression('~href="/\?expenses=this_month#widget-expense_breakdown">This month</a>~', $widget);
        self::assertStringContainsString('href="/#widget-expense_breakdown" aria-current="true">Last 12 months</a>', $widget);
        self::assertStringContainsString('href="/reports?group=fuel"', $widget, 'each row opens Reports for its group');
        self::assertStringContainsString('href="/reports?group=other"', $widget);
        $section = $this->dashboard(null, ExpensePeriod::DEFAULT)->expenseBreakdown?->sections[0];
        self::assertNotNull($section);
        foreach ($section->rows as $row) {
            self::assertStringContainsString('>' . $row->percent . '%<', $widget, 'the share as text');
        }

        $html = self::body($this->browser->get('/?expenses=this_year&vehicle=' . $golf->id));
        $widget = self::widget($html, 'expense_breakdown');
        self::assertMatchesRegularExpression('~This year</a>~', $widget);
        self::assertStringContainsString('aria-current="true">This year</a>', $widget, 'survives a refresh: it is the URL');
        self::assertStringContainsString(
            'href="/reports?range=ytd&amp;vehicle=' . $golf->id . '&amp;group=maintenance"',
            $widget,
            'the period, the vehicle and the group',
        );
        self::assertStringContainsString(
            'href="/reports?range=ytd&amp;vehicle=' . $golf->id . '"',
            self::header($html, 'expense_breakdown'),
            'the title row: Reports for the period',
        );
        // The other widgets' switches keep the choice.
        self::assertStringContainsString('expenses=this_year&amp;true_cost=since_bought', self::widget($html, 'true_cost'));

        foreach (['/?expenses=bogus', '/?expenses[]=this_month&expenses[]=this_year'] as $url) {
            self::assertStringContainsString(
                'aria-current="true">Last 12 months</a>',
                self::widget(self::body($this->browser->get($url)), 'expense_breakdown'),
                $url . ': the default',
            );
        }
    }

    public function testNothingSpentInThePeriodKeepsTheWidgetInPlace(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->expense($this->app, $golf, '2026-08-01', '12.00');

        $html = self::body($this->browser->get('/?expenses=this_month'));
        self::assertStringContainsString('No costs in this period.', self::widget($html, 'expense_breakdown'));
        self::assertStringContainsString('£12.00', self::widget($html, 'monthly_expenses'));
    }

    public function testCostsOfZeroAloneShowTheEmptyMessages(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->expense($this->app, $golf, '2026-09-01', '0.00');

        $html = self::body($this->browser->get('/'));
        self::assertStringContainsString('No costs in this period.', self::widget($html, 'expense_breakdown'));
        self::assertStringContainsString('No costs in the last 12 months.', self::widget($html, 'monthly_expenses'));
        self::assertStringNotContainsString('<table', self::widget($html, 'monthly_expenses'));
    }

    public function testTwoCurrenciesGiveTwoBlocksAndAreNeverAdded(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $peugeot = $this->vehicle($this->app, 'Peugeot', '208', 'EUR');
        $this->expense($this->app, $golf, '2026-09-01', '100.00');
        $this->expense($this->app, $peugeot, '2026-09-02', '50.00', ExpenseCategory::Tolls);

        $breakdown = $this->dashboard(null, ExpensePeriod::DEFAULT)->expenseBreakdown;
        self::assertNotNull($breakdown);
        self::assertSameAsReport($this->report(ReportRange::TwelveMonths), $breakdown);
        // In Reports' order of currencies, each with its own total.
        self::assertSame(['EUR', 'GBP'], array_map(static fn ($s): string => $s->currency, $breakdown->sections));
        self::assertSame([50_000_000, 100_000_000], array_map(static fn ($s): int => $s->total->micros, $breakdown->sections));

        $html = self::body($this->browser->get('/'));
        $widget = self::widget($html, 'expense_breakdown');
        self::assertStringContainsString('£100.00', $widget);
        self::assertStringContainsString('€50.00', $widget);
        self::assertStringNotContainsString('150.00', $widget, 'never summed');
        $monthly = self::widget($html, 'monthly_expenses');
        self::assertSame(2, substr_count($monthly, '<table'), 'a chart and a table per currency');
        self::assertStringNotContainsString('150.00', $monthly);
    }

    public function testArchivedVehiclesAndVehiclesWhoseCostsAreHiddenAreLeftOut(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $sold = $this->vehicle($this->app, 'Ford', 'Fiesta');
        $this->expense($this->app, $golf, '2026-09-01', '100.00');
        $this->expense($this->app, $sold, '2026-09-01', '40.00');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $sold);

        $breakdown = $this->dashboard(null, ExpensePeriod::DEFAULT)->expenseBreakdown;
        self::assertSame(100_000_000, $breakdown?->sections[0]->total->micros, 'not the archived car');
        self::assertSameAsReport($this->report(ReportRange::TwelveMonths), $breakdown);

        // A member who may not see the Golf's costs gets neither widget.
        $partner = $this->createMember($this->app);
        $sharing = $this->service($this->app, SharingService::class);
        self::assertNull($sharing->add($golf, 'partner', ShareLevel::View, false, false));
        $theirs = $this->browserFor($this->app, 'partner');
        $html = self::body($theirs->get('/'));
        self::assertStringNotContainsString('data-widget="expense_breakdown"', $html);
        self::assertStringNotContainsString('data-widget="monthly_expenses"', $html);
        self::assertStringNotContainsString('£100.00', $html);
        $customise = self::body($theirs->get('/?customise=1'));
        self::assertStringContainsString(
            'No vehicles whose costs you can see.',
            self::widget($customise, 'expense_breakdown'),
            'customising lists it, saying why it is empty',
        );
        self::assertStringContainsString('No vehicles whose costs you can see.', self::widget($customise, 'monthly_expenses'));

        // A second vehicle shared with its costs: only that one counts.
        $van = $this->vehicle($this->app, 'Ford', 'Transit');
        $this->expense($this->app, $van, '2026-09-03', '7.00');
        self::assertNull($sharing->add($van, 'partner', ShareLevel::View, true, false));
        $breakdown = $this->dashboardOf($partner, null, ExpensePeriod::DEFAULT)->expenseBreakdown;
        self::assertSame(7_000_000, $breakdown?->sections[0]->total->micros);
        self::assertSameAsReport(
            $this->service($this->app, ReportService::class)->build(
                $partner,
                new ReportFilter(ReportPeriod::preset(ReportRange::TwelveMonths, self::today())),
            ),
            $breakdown,
        );
        self::assertStringContainsString('£7.00', self::widget(self::body($theirs->get('/')), 'expense_breakdown'));
    }

    public function testOneVehicleSelectedShowsThatVehicleOnly(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $van = $this->vehicle($this->app, 'Ford', 'Transit');
        $this->costs($golf);
        $this->expense($this->app, $van, '2026-09-03', '7.00');

        $dashboard = $this->dashboard($golf->id, ExpensePeriod::DEFAULT);
        self::assertSameAsReport($this->report(ReportRange::TwelveMonths, $golf->id), $dashboard->expenseBreakdown);
        self::assertNotNull($dashboard->monthlySpend);
        self::assertEquals(
            $this->report(ReportRange::TwelveMonths, $golf->id)->currencies,
            $dashboard->monthlySpend->report->currencies,
        );

        $html = self::body($this->browser->get('/?vehicle=' . $golf->id));
        self::assertStringContainsString(
            'href="/reports?range=custom&amp;from=2026-09-01&amp;to=2026-09-30&amp;vehicle=' . $golf->id . '"',
            self::widget($html, 'monthly_expenses'),
            'a month keeps the vehicle',
        );
        self::assertStringContainsString('href="/reports?vehicle=' . $golf->id . '"', self::header($html, 'monthly_expenses'));
    }

    public function testMonthlySpendListsTwelveMonthsAndEachLinksToReports(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        $this->costs($golf);

        $spend = $this->dashboard(null, ExpensePeriod::DEFAULT)->monthlySpend;
        self::assertNotNull($spend);
        $report = $this->report(ReportRange::TwelveMonths);
        self::assertEquals($report->currencies, $spend->report->currencies, "Reports' last 12 months");
        $section = $spend->report->currencies[0];
        self::assertCount(12, $section->months, 'every month, empty ones included');
        self::assertSame('2025-10', $section->months[0]->month->format('Y-m'));
        self::assertTrue($section->months[1]->month->format('Y-m') === '2025-11' && !$section->months[1]->total->isZero());
        self::assertTrue($section->months[0]->total->isZero(), 'October 2025: nothing, still listed');
        self::assertEquals($report->currencies[0]->averagePerMonth, $section->averagePerMonth, 'divided as Reports divides');

        $html = self::body($this->browser->get('/'));
        $widget = self::widget($html, 'monthly_expenses');
        preg_match_all('~<th scope="row"><a href="([^"]+)">([^<]+)</a></th>~', $widget, $rows);
        self::assertCount(12, $rows[1], 'twelve rows, newest first');
        self::assertSame('/reports?range=custom&amp;from=2026-09-01&amp;to=2026-09-30', $rows[1][0]);
        self::assertSame('/reports?range=custom&amp;from=2025-10-01&amp;to=2025-10-31', $rows[1][11]);
        self::assertSame('Sept 2026', $rows[2][0]);
        self::assertStringContainsString('a month on average', $widget);
        self::assertStringContainsString('href="/reports"', self::header($html, 'monthly_expenses'));

        // The chart carries the table's figures and the same links, oldest first.
        $chart = self::chart($widget);
        self::assertSame(array_reverse(array_map(
            static fn (string $href): string => html_entity_decode($href),
            $rows[1],
        )), $chart['links']);
        self::assertCount(12, $chart['labels']);
        $september = 0.0;
        foreach ($chart['series'] as $series) {
            self::assertCount(12, $series['values']);
            $september += $series['values'][11] ?? 0.0;
        }
        self::assertEqualsWithDelta($section->months[11]->total->toFloat(), $september, 0.001, 'chart and table agree');
    }

    public function testAMonthStartsAtLocalMidnightAcrossDaylightSaving(): void
    {
        $this->start();
        $golf = $this->vehicle($this->app);
        // 00:30 BST on 1 April 2026 is 23:30 UTC on 31 March.
        $this->fillUp($this->app, $golf, '2026-03-31T23:30:00Z', '9900', '30', '45.00');

        $months = $this->dashboard(null, ExpensePeriod::DEFAULT)->monthlySpend?->report->currencies[0]->months ?? [];
        $byMonth = [];
        foreach ($months as $month) {
            $byMonth[$month->month->format('Y-m')] = $month->total->micros;
        }
        self::assertSame(0, $byMonth['2026-03'] ?? null);
        self::assertSame(45_000_000, $byMonth['2026-04'] ?? null, 'counted in April');
    }

    public function testReportsOffRemovesBothAndOnBringsThemBackInPlace(): void
    {
        $this->start();
        $this->vehicle($this->app);
        $this->browser->get('/?customise=1');
        $this->browser->post('/dashboard/layout', ['widget' => 'monthly_expenses', 'move' => 'up']);
        $before = self::widgetOrder(self::body($this->browser->get('/')));
        self::assertSame(
            ['spend', 'monthly_expenses', 'expense_breakdown', 'recent_fuel'],
            array_slice($before, (int) array_search('spend', $before, true), 4),
        );

        $settings = $this->service($this->app, SettingRepository::class);
        $layout = $settings->find(DashboardLayoutStore::SETTING, SettingScope::User, $this->owner($this->app)->id)?->value;
        $toggles = static fn (bool $on): array => ['fuel' => true, 'compliance' => true, 'reports' => $on];
        $settings->save(FeatureToggles::SETTING, $toggles(false), SettingScope::Global);
        foreach (['/', '/?customise=1'] as $url) {
            $order = self::widgetOrder(self::body($this->browser->get($url)));
            self::assertNotContains('expense_breakdown', $order, $url);
            self::assertNotContains('monthly_expenses', $order, $url);
            self::assertNotContains('spend', $order, $url);
        }
        self::assertSame(
            $layout,
            $settings->find(DashboardLayoutStore::SETTING, SettingScope::User, $this->owner($this->app)->id)?->value,
            'the saved layout keeps their places',
        );

        $settings->save(FeatureToggles::SETTING, $toggles(true), SettingScope::Global);
        self::assertSame($before, self::widgetOrder(self::body($this->browser->get('/'))), 'back where they were');
    }

    public function testTheSpendWidgetsAddNoQueriesHoweverManyVehicles(): void
    {
        $app = $this->createApp();
        $counter = QueryCounter::install($app);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->app = $app;

        $extra = [];
        foreach ([2, 6] as $count) {
            while (count($this->service($app, VehicleService::class)->listFleet($this->owner($app))) < $count) {
                $vehicle = $this->vehicle($app, 'Make' . $count, 'Model' . random_int(0, 99999));
                $this->expense($app, $vehicle, '2026-09-01', '10.00');
                $this->maintenance($app, $vehicle, '2026-02-01', 'Service', '100.00');
            }
            $owner = $this->owner($app)->id;
            $settings = $this->service($app, SettingRepository::class);
            $settings->save(DashboardLayoutStore::SETTING, [
                'order' => [],
                'hidden' => ['expense_breakdown', 'monthly_expenses'],
            ], SettingScope::User, $owner);
            $browser->get('/');
            $hidden = $counter->during(static fn () => $browser->get('/'));
            $settings->save(DashboardLayoutStore::SETTING, ['order' => [], 'hidden' => []], SettingScope::User, $owner);
            $browser->get('/');
            $shown = $counter->during(static fn () => $browser->get('/'));
            $extra[$count] = $shown - $hidden;
        }

        // They share Spend this month's read of the ledger; what is left is
        // the "costs not included" note's own fixed lookup.
        self::assertSame($extra[2], $extra[6], 'the same extra queries for two vehicles and for six');
        self::assertLessThanOrEqual(4, $extra[2]);
    }

    private function start(): void
    {
        $this->app = $this->createApp();
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
    }

    /**
     * Costs in every group across the last 12 months and before them.
     */
    private function costs(Vehicle $vehicle): void
    {
        $this->expense($this->app, $vehicle, '2025-08-15', '30.00'); // before the 12 months
        $this->fillUp($this->app, $vehicle, '2025-11-05T08:00:00Z', '9000', '40', '58.00');
        $this->document($this->app, $vehicle, ComplianceType::Insurance, '2026-01-10', '2027-01-09', '420.00');
        $this->maintenance($this->app, $vehicle, '2026-03-15', 'Annual service', '180.00');
        $this->fillUp($this->app, $vehicle, '2026-09-10T08:00:00Z', '10800', '45.461', '66.64');
        $this->expense($this->app, $vehicle, '2026-09-20', '2.50', ExpenseCategory::Tolls);
    }

    private function dashboard(?int $vehicleId, ExpensePeriod $period): Dashboard
    {
        return $this->dashboardOf($this->owner($this->app), $vehicleId, $period);
    }

    private function dashboardOf(User $user, ?int $vehicleId, ExpensePeriod $period): Dashboard
    {
        return $this->service($this->app, DashboardService::class)
            ->build($user, $vehicleId, TrueCostRange::TwelveMonths, $period);
    }

    /**
     * What Reports shows the owner for a preset (the fleet, or one vehicle).
     */
    private function report(ReportRange $range, ?int $vehicleId = null): Report
    {
        return $this->service($this->app, ReportService::class)->build(
            $this->owner($this->app),
            new ReportFilter(ReportPeriod::preset($range, self::today()), $vehicleId),
        );
    }

    private static function assertSameAsReport(Report $report, ?ExpenseBreakdown $breakdown): void
    {
        self::assertNotNull($breakdown);
        $expected = [];
        foreach ($report->currencies as $currency) {
            if ($currency->isEmpty()) {
                continue;
            }
            $groups = [];
            foreach ($currency->spentGroups() as $group) {
                $groups[$group->group->value] = $group->amount->micros;
            }
            $expected[] = [$currency->currency, $currency->total->micros, $groups];
        }
        $actual = [];
        foreach ($breakdown->sections as $section) {
            $groups = [];
            foreach ($section->rows as $row) {
                $groups[$row->group->value] = $row->amount->micros;
            }
            $actual[] = [$section->currency, $section->total->micros, $groups];
        }
        self::assertSame($expected, $actual, 'the breakdown is the report, group by group');
    }

    private static function today(): DateTimeImmutable
    {
        $today = LocalTime::parseDate(self::TODAY);
        self::assertNotNull($today);

        return $today;
    }

    private static function widget(string $html, string $id): string
    {
        $found = preg_match('~<section class="widget[^"]*" id="widget-' . $id . '".*?</section>~s', $html, $match);
        self::assertSame(1, $found, $id . ' is on the page');

        return $match[0];
    }

    /**
     * The first chart's data in a widget (Support\View\BarChart's JSON).
     *
     * @return array{labels: list<string>, links: list<string>, series: list<array{values: list<?float>}>}
     */
    private static function chart(string $widget): array
    {
        $data = preg_match('~<canvas data-chart="([^"]+)"~', $widget, $canvas) === 1 ? $canvas[1] : '';
        self::assertNotSame('', $data, 'a chart in the widget');
        $chart = json_decode(html_entity_decode($data), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($chart);
        /** @var array{labels: list<string>, links: list<string>, series: list<array{values: list<?float>}>} $chart */
        return $chart;
    }

    private static function header(string $html, string $id): string
    {
        $widget = self::widget($html, $id);
        $end = strpos($widget, '</h2>');

        return substr($widget, 0, $end === false ? strlen($widget) : (int) strpos($widget, '</div>', $end));
    }

    /**
     * @return list<string>
     */
    private static function widgetOrder(string $html): array
    {
        preg_match_all('/<section class="widget[^"]*" id="widget-[a-z_]+" data-widget="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }
}
