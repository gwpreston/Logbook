<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * *How it’s worked out* on the pages (spec.md §8 *Show the working*,
 * Phase 48.2): the Golf of CostOfOwnershipPageTest, bought £15,000 on
 * 1 Mar 2023, valued £9,800 on 1 Mar 2026, with £7,000 of fuel and £4,700
 * of other costs over 39,000 mi. "Today" is 1 Sep 2026.
 */
final class WorkingTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-01T10:00:00Z';

    public function testTheOwnershipTabOpensToItsSumAndKeepsTheOldWording(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/ownership'));

        self::assertStringContainsString('<details class="working" data-working>', $html, 'closed: no open attribute');
        self::assertStringContainsString(
            'Depreciation: price paid £15,000.00 − latest value £9,800.00 (1 Mar 2026) = £5,200.00.',
            $html,
        );
        self::assertStringContainsString('Total: £11,700.00 of running costs + £5,200.00 of depreciation = £16,900.00.', $html);
        self::assertStringContainsString(
            'Depreciation compares the price you paid with its latest value.',
            $html,
            'the old wording',
        );
        self::assertStringContainsString('Logbook doesn’t estimate values or apply a depreciation rate', $html);
        self::assertStringContainsString('data-as-of="valuation">as of 1 Mar 2026</span>', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/valuations"', $html, 'the input links to its rows');
        // The distance, with the two readings it is the difference of.
        self::assertStringContainsString('39,000 mi', $html);
        self::assertStringContainsString('your odometer readings of 1 Mar 2023 (6,214 mi)', $html);
    }

    public function testTheOverviewCardAndTheReportGiveTheSameWorking(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);

        $overview = self::body($browser->get('/vehicles/' . $golf->id));
        $report = self::body($browser->get('/reports/ownership?vehicle=' . $golf->id));

        foreach ([$overview, $report] as $html) {
            self::assertStringContainsString('= £16,900.00.', $html);
            self::assertStringContainsString('class="working"', $html);
        }
    }

    public function testTrueCostOpensToItsPartsAndDepreciationWithTheLine(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2025-03-01'), '12000'));

        $html = self::body($browser->get('/reports/true-cost?vehicle=' . $golf->id));

        self::assertStringContainsString('How 2026 so far is worked out', $html);
        self::assertStringContainsString('straight line between your valuation of 1 Mar 2025, £12,000.00', $html);
        self::assertStringContainsString('only draws a straight line between values you entered', $html);
        self::assertStringContainsString('Fuel £', $html);
        self::assertStringContainsString('Distance driven', $html);
    }

    public function testAFillUpOpensToTheStretchItsEconomyIsMeasuredOver(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->fillUp($app, $golf, '2023-07-01T08:00:00Z', '13000', '50', '75.00');

        $html = self::body($browser->get('/vehicles/' . $golf->id . '/fuel'));

        self::assertStringContainsString(
            '621 mi between your full fill-ups of 1 Jun 2023 (odometer 7,456 mi) and 1 Jul 2023 (odometer 8,078 mi).',
            $html,
        );
        self::assertStringContainsString('was bought after the first one, up to and including the second, in 1 fill-up.', $html);
        self::assertStringContainsString('Full fill-up that starts it', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel/', $html, 'each end links to its fill-up');
    }

    public function testTheReportsCostPerDistanceNamesItsCostsDistanceAndWhatIsLeftOut(): void
    {
        [$app, $browser] = $this->start();
        $this->golf($app);

        $html = self::body($browser->get('/reports?range=custom&from=2023-03-01&to=2026-09-01'));

        self::assertMatchesRegularExpression('~of costs logged in the period ÷ [\d,]+ mi driven = £[\d.]+/mi\.~u', $html);
        self::assertStringContainsString('Depreciation is left out of this figure', $html);
        self::assertStringContainsString('between your odometer readings of', $html);
        self::assertStringContainsString('How Running cost is worked out', $html);
    }

    public function testEveryInputLinksToARowThatExists(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->fillUp($app, $golf, '2023-07-01T08:00:00Z', '13000', '50', '75.00');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2025-03-01'), '12000'));

        $links = [];
        $pages = [
            '/vehicles/' . $golf->id . '/ownership',
            '/vehicles/' . $golf->id,
            '/reports/ownership',
            '/reports/true-cost',
            '/reports?range=custom&from=2023-03-01&to=2026-09-01',
            '/vehicles/' . $golf->id . '/fuel',
            '/upcoming',
        ];
        foreach ($pages as $page) {
            $html = self::body($browser->get($page));
            preg_match_all('~<dl class="working__inputs">(.*?)</dl>~s', $html, $lists);
            foreach ($lists[1] as $list) {
                preg_match_all('~<a href="([^"]+)"~', $list, $hrefs);
                foreach ($hrefs[1] as $href) {
                    $links[html_entity_decode($href)] = $page;
                }
            }
        }

        self::assertNotSame([], $links, 'the pages have inputs with links');
        foreach ($links as $href => $page) {
            self::assertSame(200, $browser->get($href)->getStatusCode(), $href . ' from ' . $page);
        }
    }

    public function testWithoutCostAccessThereIsNoWorking(): void
    {
        [$app] = $this->start();
        $golf = $this->golf($app);
        $viewer = $this->createMember($app, 'viewer');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $viewer->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $theirs = $this->browserFor($app, 'viewer');

        $pages = ['/vehicles/' . $golf->id, '/reports/ownership', '/reports/true-cost', '/vehicles/' . $golf->id . '/finance'];
        foreach ($pages as $path) {
            $html = self::body($theirs->get($path));
            self::assertStringNotContainsString('data-working', $html, $path);
            self::assertStringNotContainsString('£16,900.00', $html, $path);
        }
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: \Logbook\Tests\Support\TestBrowser}
     */
    private function start(): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app): Vehicle
    {
        $golf = $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            registration: 'GO19 ABC',
            purchaseDate: self::date('2023-03-01'),
            purchasePrice: '15000',
        ));
        $this->reading($app, $golf, '10000', '2023-03-01T12:00:00Z');
        $this->fillUp($app, $golf, '2023-06-01T08:00:00Z', '12000', '4000', '7000.00');
        $this->expense($app, $golf, '2024-05-10', '4700', ExpenseCategory::Other, 'Everything else');
        $this->reading($app, $golf, '67936.384', '2026-03-01T12:00:00Z');
        $this->reading($app, $golf, '72764.416', '2026-08-31T12:00:00Z');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2026-03-01'), '9800'));

        return $golf;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
