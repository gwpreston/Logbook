<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Expense\CostLedger;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *Cost 0, and every figure at 0 distance*; spec §8 *Money
 * edge cases*, bullets **Cost 0** and **No distance**, with §7.7 *Cost
 * ledger* and *Distance driven* (decided #376, #398, #405, #386–#392).
 *
 * A £0 fill-up or ad-hoc expense is a ledger line and counts everywhere; a
 * £0 service or document is not a line. A period whose only costs are £0
 * lines has a £0.00 rate, not "—". Zero distance, one reading, none, a
 * backwards reading inside the period and a period that ends below its start
 * each behave as the spec says. Every "—" case meets the other conditions
 * (purchase date, a reading on the purchase day, 90 days, a cost), so the
 * case under test is the only reason for the dash.
 *
 * Today is 5 Oct 2026; the vehicle was bought 1 Jan 2026 (277 days).
 */
final class CostZeroAndNoDistanceTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';
    private const string TODAY = '2026-10-05';

    public function testAZeroFillUpAndAZeroExpenseAreLedgerLinesCountedInEveryTotalAndCount(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->logged($app, '5000');
        $this->fillUp($app, $car, '2026-03-10T09:00:00Z', '2000', '10.000', '0.00');
        $this->expense($app, $car, '2026-04-01', '0');
        $this->expense($app, $car, '2026-05-01', '12.50');

        $items = $this->service($app, CostLedger::class)->items($owner, [$car]);
        self::assertCount(3, $items, 'the £0 fill-up and the £0 expense are lines');

        $report = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertSame(3, $report->count, 'Reports counts both £0 lines');
        self::assertSame(3, $report->vehicles[0]->count, 'and so does the vehicle row');
        self::assertSame('12.500', $report->total->toDecimal(3));
        self::assertSame('0.000', $report->group(CostGroup::Fuel)->toDecimal(3), 'the fill-up adds no fuel money');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertSame(3, $result->ownership->count, 'cost of ownership counts them too');
        self::assertSame('12.500', $result->ownership->running->toDecimal(3));
    }

    public function testAZeroServiceAndAZeroDocumentAreNotLedgerLines(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->logged($app, '5000');
        $this->maintenance($app, $car, '2026-03-10', 'Free check', '0');
        $this->document($app, $car, ComplianceType::Insurance, '2026-02-01', '2027-01-31', '0');
        $this->expense($app, $car, '2026-05-01', '5.00');

        self::assertCount(1, $this->service($app, CostLedger::class)->items($owner, [$car]), 'only the expense');
        $report = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertSame(1, $report->count);
        self::assertSame(1, $this->trueCost($app, $owner, $car, self::TODAY)->ownership->count);
    }

    public function testAPeriodWhoseOnlyCostsAreZeroLinesShowsAZeroRateNotADash(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->logged($app, '5000');
        $this->fillUp($app, $car, '2026-03-10T09:00:00Z', '2000', '10.000', '0.00');
        $this->expense($app, $car, '2026-04-01', '0');

        $report = $this->report($app, $owner, $car, '2026-02-01', self::TODAY);
        self::assertSame(2, $report->count);
        self::assertDecimal('4000', $report->distanceKm, 'distance from the readings');
        self::assertDecimal('0', $report->costPerKm, 'Reports: £0 over 4,000 km');
        self::assertSame('£0.00/mi', $this->shown($app, $owner)->perDistance($report->costPerKm, 'GBP'));

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertDecimal('0', $result->ownership->runningPerKm, 'cost of ownership');
        self::assertDecimal('0', $result->ownership->perKm);
        self::assertNull($result->sinceBought->gap);
        self::assertDecimal('0', $result->sinceBought->perKm, 'true cost since bought');
        self::assertNotNull($result->lastTwelveMonths);
        self::assertNull($result->lastTwelveMonths->gap);
        self::assertDecimal('0', $result->lastTwelveMonths->perKm, 'true cost, last 12 months');
        self::assertCount(1, $result->years);
        self::assertDecimal('0', $result->years[0]->perKm, 'true cost, 2026');
    }

    public function testTheReportsPageShowsAZeroRateForOnlyZeroLinesAndADashForZeroDistance(): void
    {
        [$app] = $this->world(self::NOW);
        $free = $this->logged($app, '5000');
        $this->expense($app, $free, '2026-04-01', '0');
        $still = $this->car($app, 'Polo', '2026-01-01', '10000');
        $this->reading($app, $still, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $still, '1000', '2026-09-01T12:00:00Z');
        $this->expense($app, $still, '2026-04-01', '25.00');
        $browser = $this->browserFor($app, 'owner');

        self::assertSame('£0.00/mi', $this->runningCost($browser, $free), 'only a £0 line: a rate of nothing, not "—"');
        self::assertSame('—', $this->runningCost($browser, $still), 'no distance driven');
    }

    public function testTheCardTheOwnershipPageAndTheTrueCostPagesShowAZeroRateForOnlyZeroLines(): void
    {
        [$app] = $this->world(self::NOW);
        $car = $this->logged($app, '5000');
        $this->expense($app, $car, '2026-04-01', '0');
        $browser = $this->browserFor($app, 'owner');

        foreach (['/vehicles/' . $car->id, '/vehicles/' . $car->id . '/ownership', '/reports/ownership'] as $url) {
            $page = $this->pageText($browser, $url);
            self::assertStringContainsString('£0.00/mi', $page, $url . ': cost of ownership');
            self::assertStringNotContainsString('Not enough mileage logged', $page, $url);
        }
        $trueCost = [
            '/reports/true-cost?vehicle=' . $car->id => 'Since bought: £0.00/mi',
            '/' => 'Make Golf £0.00/mi (running costs only)',
        ];
        foreach ($trueCost as $url => $expected) {
            $page = $this->pageText($browser, $url);
            self::assertStringContainsString($expected, $page, $url . ': true cost');
            self::assertStringNotContainsString('Not enough mileage logged', $page, $url);
        }
    }

    public function testTwoEqualReadingsMeanZeroDistanceAndNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '1000', '2026-09-01T12:00:00Z');
        $this->expense($app, $car, '2026-05-01', '25.00');

        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    public function testAVehicleWithNoReadingsHasNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->expense($app, $car, '2026-05-01', '25.00');

        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    public function testAVehicleWithOneReadingHasNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->expense($app, $car, '2026-05-01', '25.00');

        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    public function testABackwardsReadingInsideAPeriodIsIgnoredAndOnlyTheEndPointsCount(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '3000', '2026-03-01T12:00:00Z');
        $this->reading($app, $car, '2000', '2026-06-01T12:00:00Z');
        $this->reading($app, $car, '5000', '2026-09-01T12:00:00Z');
        $this->expense($app, $car, '2026-05-01', '400.00');

        $report = $this->report($app, $owner, $car, '2026-01-01', self::TODAY);
        self::assertDecimal('4000', $report->distanceKm, 'from 1,000 to 5,000, whatever lay between');
        self::assertDecimal('0.1', $report->costPerKm, 'Reports: £400 over 4,000 km');

        $result = $this->trueCost($app, $owner, $car, self::TODAY);
        self::assertDecimal('4000', $result->ownership->distanceKm, 'cost of ownership');
        self::assertDecimal('0.1', $result->ownership->runningPerKm);
        self::assertDecimal('4000', $result->sinceBought->distanceKm, 'true cost');
    }

    public function testAPeriodWhoseEndReadingIsBelowItsStartHasNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '3000', '2026-03-01T12:00:00Z');
        $this->reading($app, $car, '2500', '2026-09-01T12:00:00Z');
        $this->expense($app, $car, '2026-05-01', '40.00');

        // From April: the last reading before it is 3,000 and the last in it 2,500.
        $report = $this->report($app, $owner, $car, '2026-04-01', self::TODAY);
        self::assertSame(1, $report->count);
        self::assertNull($report->distanceKm);
        self::assertNull($report->costPerKm, 'the period ends below where it started');
    }

    public function testAnOwnershipPeriodWhoseEndReadingIsBelowItsStartHasNoPerDistanceFigure(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '3000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, '2500', '2026-09-01T12:00:00Z');
        $this->expense($app, $car, '2026-05-01', '40.00');

        $this->assertNoPerDistance($app, $owner, $car, self::TODAY);
    }

    private function runningCost(TestBrowser $browser, Vehicle $car): string
    {
        $page = self::body($browser->get('/reports?range=custom&from=2026-02-01&to=2026-10-05&vehicle=' . $car->id));
        $stat = '~<dt class="stat__label">Running cost</dt>\s*<dd class="stat__value tabular">([^<]*)</dd>~';
        $found = preg_match($stat, $page, $m);
        self::assertSame(1, $found, 'the Reports page has a running cost stat');

        return trim($m[1]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function logged(App $app, string $endKm): Vehicle
    {
        $car = $this->car($app, 'Golf', '2026-01-01', '10000');
        $this->reading($app, $car, '1000', '2026-01-01T12:00:00Z');
        $this->reading($app, $car, $endKm, '2026-09-01T12:00:00Z');

        return $car;
    }
}
