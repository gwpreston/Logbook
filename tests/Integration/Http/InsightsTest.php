<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\FuelPrices\PriceChange;
use Logbook\Domain\FuelPrices\StationLink;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ListedPriceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\TripRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Logbook\Service\Insights\InsightKind;
use Logbook\Service\Insights\InsightsService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Integration\FuelPrices\FuelPricesTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The dashboard's *Insights* widget (Phase 33.3, spec.md §7.8): each kind
 * from its fixture, in the spec's order, only the first two shown; cost
 * insights never without `ViewCosts`; a switched-off module takes its
 * insight with it; *cheapest to run* only for *All vehicles* and within
 * one currency; equity only with a recent valuation; and "Nothing stands
 * out right now." when there is nothing to say. "Today" is 3 Oct 2026.
 */
final class InsightsTest extends FuelPricesTestCase
{
    private Station $tesco;
    private Station $shell;

    public function testNothingStandsOutWithoutFigures(): void
    {
        [$app] = $this->pricesApp();
        $this->allModules($app);
        $this->vehicle($app);

        $html = self::body($this->browserFor($app, 'owner')->get('/'));
        self::assertStringContainsString('id="widget-insights"', $html);
        self::assertStringContainsString('Nothing stands out right now.', $html);
        self::assertSame([], self::insights($html));
    }

    public function testShoppingAroundFromTheFuelTabsFigure(): void
    {
        [$app, $golf] = $this->shoppingScene();
        $browser = $this->browserFor($app, 'owner');

        $html = self::body($browser->get('/'));
        self::assertSame(['shopping_around'], self::insights($html));
        self::assertStringContainsString('About £2.40 better off from shopping around', $html);
        self::assertStringContainsString(
            'Volkswagen Golf: 3 fill-ups away from your usual station over the last 12 months, before the extra driving.',
            $html,
        );
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel" data-insight="shopping_around"', $html);

        // Fuel stations off: the Fuel tab has no *Shopping around*, nor does the widget.
        $this->modulesExcept($app, Feature::Stations);
        self::assertSame([], self::insights(self::body($browser->get('/'))));
    }

    public function testBusinessMileageFromTheClaimReport(): void
    {
        [$app] = $this->pricesApp();
        $this->allModules($app);
        $golf = $this->vehicle($app);
        $this->trip($app, $golf, '2026-07-01', '160.934');
        $browser = $this->browserFor($app, 'owner');

        $html = self::body($browser->get('/'));
        self::assertSame(['business_mileage'], self::insights($html));
        self::assertStringContainsString('£55.00 claimable in business mileage', $html);
        self::assertStringContainsString('100 mi of business trips since 6 Apr 2026, at your mileage rates.', $html);
        self::assertStringContainsString('href="/trips/claim" data-insight="business_mileage"', $html);

        $this->modulesExcept($app, Feature::Trips);
        self::assertSame([], self::insights(self::body($browser->get('/'))));
    }

    public function testCheapestToRunComparesTheFleetInOneCurrency(): void
    {
        [$app] = $this->pricesApp();
        $this->allModules($app);
        [$golf, $polo] = $this->twoCars($app);
        // Under 500 km in the 12 months: left out, however cheap.
        $up = $this->vehicle($app, 'Volkswagen', 'Up');
        $this->fillUp($app, $up, '2026-02-01T08:00:00Z', '5000', '10', '1.00');
        $this->fillUp($app, $up, '2026-09-01T08:00:00Z', '5400', '10', '1.00');
        $browser = $this->browserFor($app, 'owner');

        $html = self::body($browser->get('/'));
        self::assertSame(['cheapest_to_run'], self::insights($html));
        self::assertStringContainsString('Volkswagen Polo is your cheapest to run', $html);
        // £100 over 2,000 km against £120 over 1,000 km, per mile.
        self::assertStringContainsString(
            '£0.08/mi over the last 12 months, against £0.193/mi for Volkswagen Golf. Running costs only.',
            $html,
        );
        self::assertStringContainsString('href="/reports" data-insight="cheapest_to_run"', $html);

        // One vehicle chosen: fleet only.
        self::assertSame([], self::insights(self::body($browser->get('/?vehicle=' . $golf->id))));
        // Reports off: there is nowhere to show the figure.
        $this->modulesExcept($app, Feature::Reports);
        self::assertSame([], self::insights(self::body($browser->get('/'))));
        unset($polo);
    }

    public function testCheapestToRunNeverConvertsCurrencies(): void
    {
        [$app] = $this->pricesApp();
        $this->allModules($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-01-01T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '11000', '40', '60.00');
        $seat = $this->vehicle($app, 'Seat', 'Ibiza', 'EUR');
        $this->fillUp($app, $seat, '2026-01-01T08:00:00Z', '30000', '40', '40.00');
        $this->fillUp($app, $seat, '2026-09-01T08:00:00Z', '32000', '40', '40.00');
        $browser = $this->browserFor($app, 'owner');

        // One vehicle in each currency: nothing to compare within either.
        self::assertSame([], self::insights(self::body($browser->get('/'))));

        // Two in euros: they are compared, the pound car left out.
        $skoda = $this->vehicle($app, 'Skoda', 'Fabia', 'EUR');
        $this->fillUp($app, $skoda, '2026-01-01T08:00:00Z', '40000', '40', '90.00');
        $this->fillUp($app, $skoda, '2026-09-01T08:00:00Z', '41000', '40', '90.00');
        $html = self::body($browser->get('/'));
        self::assertSame(['cheapest_to_run'], self::insights($html));
        self::assertStringContainsString('Seat Ibiza is your cheapest to run', $html);
        self::assertStringContainsString('for Skoda Fabia', $html);
        self::assertStringContainsString('€', $html);
        self::assertStringNotContainsString('for Volkswagen Golf', $html);
    }

    public function testEquityNeedsARecentValuation(): void
    {
        [$app] = $this->pricesApp();
        $this->allModules($app);
        $golf = $this->vehicle($app);
        $browser = $this->browserFor($app, 'owner');
        $this->pcp($browser, $golf);

        // No valuation: no equity, and never an estimate of the value.
        self::assertSame([], self::insights(self::body($browser->get('/'))));
        // Over 12 months old: still none.
        $this->valuation($app, $golf, '2025-09-01', '30000');
        self::assertSame([], self::insights(self::body($browser->get('/'))));

        $this->valuation($app, $golf, '2026-09-20', '25000');
        $html = self::body($browser->get('/'));
        self::assertSame(['equity'], self::insights($html));
        self::assertMatchesRegularExpression('/Volkswagen Golf has about £[\d,]+ of equity/', $html);
        self::assertStringContainsString('Valued at £25,000 against an estimated settlement of £', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/finance" data-insight="equity"', $html);

        $this->valuation($app, $golf, '2026-09-25', '5000');
        $html = self::body($browser->get('/'));
        self::assertMatchesRegularExpression('/Volkswagen Golf is about £[\d,]+ in negative equity/', $html);
        self::assertStringContainsString('insight__tile--watch', $html);

        $this->modulesExcept($app, Feature::Finance);
        self::assertSame([], self::insights(self::body($browser->get('/'))));
    }

    public function testOnlyTheFirstTwoInTheSpecsOrder(): void
    {
        [$app, $golf] = $this->shoppingScene();
        $this->allModules($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $polo, '2026-01-01T08:00:00Z', '20000', '40', '50.00');
        $this->fillUp($app, $polo, '2026-09-01T08:00:00Z', '22000', '40', '50.00');
        $this->trip($app, $golf, '2026-07-01', '160.934');
        $browser = $this->browserFor($app, 'owner');
        $this->pcp($browser, $golf);
        $this->valuation($app, $golf, '2026-09-20', '25000');

        self::assertSame(['shopping_around', 'business_mileage'], self::insights(self::body($browser->get('/'))));

        // All four exist, in order, for the Insights page to come (Phase 33.4).
        $all = $this->service($app, InsightsService::class)->forVehicles(
            $this->owner($app),
            $this->service($app, VehicleService::class)->listFleet($this->owner($app)),
            true,
            LocalTime::parseDate('2026-10-03') ?? self::fail('date'),
        );
        self::assertSame(
            [InsightKind::ShoppingAround, InsightKind::BusinessMileage, InsightKind::CheapestToRun, InsightKind::Equity],
            array_map(static fn ($insight): InsightKind => $insight->kind, $all),
        );
    }

    public function testTheInsightsPageShowsEveryInsightAndTheWidgetLinksToIt(): void
    {
        [$app, $golf] = $this->shoppingScene();
        $this->allModules($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $polo, '2026-01-01T08:00:00Z', '20000', '40', '50.00');
        $this->fillUp($app, $polo, '2026-09-01T08:00:00Z', '22000', '40', '50.00');
        $this->trip($app, $golf, '2026-07-01', '160.934');
        $browser = $this->browserFor($app, 'owner');
        $this->pcp($browser, $golf);
        $this->valuation($app, $golf, '2026-09-20', '25000');

        $page = self::body($browser->get('/insights'));
        preg_match_all('/<li class="insight-card" data-insight="([a-z_]+)"/', $page, $kinds);
        $every = ['shopping_around', 'business_mileage', 'cheapest_to_run', 'equity'];
        self::assertSame($every, $kinds[1], 'all of them (Phase 33.4)');
        self::assertStringContainsString('See the claim', $page);
        self::assertStringContainsString('<title>Insights · Logbook</title>', $page);
        self::assertStringNotContainsString('data-ask-form', $page, 'no Ask box without AI');

        $home = self::body($browser->get('/'));
        $widget = substr($home, (int) strpos($home, 'id="widget-insights"'), 2000);
        self::assertStringContainsString('href="/insights"', $widget, 'All insights');
    }

    public function testTheInsightsPageWithNothingToShow(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-10-03T12:00:00Z');
        $browser = $this->signedIn($app);

        $page = self::body($browser->get('/insights'));

        self::assertStringContainsString('Log a few more fill-ups and services and patterns will show up here.', $page);
        self::assertStringContainsString('aria-current="page"', substr($page, (int) strpos($page, 'href="/insights"'), 80));
    }

    public function testCostInsightsNeverShowWithoutCosts(): void
    {
        [$app, $golf] = $this->shoppingScene();
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $polo, '2026-01-01T08:00:00Z', '20000', '40', '50.00');
        $this->fillUp($app, $polo, '2026-09-01T08:00:00Z', '22000', '40', '50.00');
        $owner = $this->browserFor($app, 'owner');
        $this->pcp($owner, $golf);
        $this->valuation($app, $golf, '2026-09-20', '25000');
        self::assertSame(['shopping_around', 'cheapest_to_run'], self::insights(self::body($owner->get('/'))));

        // A View share without costs, and one with: the same vehicles.
        $shares = $this->service($app, VehicleShareRepository::class);
        $at = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $partner = $this->createMember($app);
        $viewer = $this->createMember($app, 'viewer');
        foreach ([$golf, $polo] as $vehicle) {
            $shares->insert($vehicle->id, $partner->id, ShareLevel::View, false, false, $at);
            $shares->insert($vehicle->id, $viewer->id, ShareLevel::View, true, false, $at);
        }

        $html = self::body($this->browserFor($app, 'partner')->get('/'));
        self::assertStringContainsString('Volkswagen Golf', $html, 'the shared vehicles are on the dashboard');
        self::assertSame([], self::insights($html));
        self::assertStringContainsString('Nothing stands out right now.', $html);

        // With costs but only View: shopping around and the comparison, never equity (Manage only).
        $all = $this->service($app, InsightsService::class)->forVehicles(
            $viewer,
            $this->service($app, VehicleService::class)->listFleet($viewer),
            true,
            LocalTime::parseDate('2026-10-03') ?? self::fail('date'),
        );
        self::assertSame(
            [InsightKind::ShoppingAround, InsightKind::CheapestToRun],
            array_map(static fn ($insight): InsightKind => $insight->kind, $all),
        );
    }

    /**
     * ComparisonsTest's Golf: four fill-ups at Tesco (its usual station),
     * then three at Shell, each cheaper than Tesco's listed price by then.
     *
     * @return array{App<ContainerInterface>, Vehicle}
     */
    private function shoppingScene(): array
    {
        [$app] = $this->pricesApp();
        $now = new DateTimeImmutable(self::NOW);
        $stations = $this->service($app, StationRepository::class);
        $this->tesco = $this->station($app, 'Tesco', '54.718012', '-6.219034');
        $this->shell = $this->station($app, 'Shell', '54.705000', '-6.240000');
        $stations->setLink($this->tesco->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-tesco')), $now);
        $stations->setLink($this->shell->id, new StationLink(FuelFinderProvider::CODE, self::ref('antrim-shell')), $now);

        $golf = $this->vehicle($app);
        $fills = ['2026-06-01' => '9400', '2026-07-01' => '10000', '2026-08-01' => '10600', '2026-09-01' => '11200'];
        foreach ($fills as $day => $km) {
            $this->fillAt($app, $golf, $this->tesco, $day . 'T08:00:00Z', $km, '1.399');
        }
        $history = $this->service($app, ListedPriceRepository::class);
        $prices = [
            ['antrim-tesco', '1.399', '2026-09-15T07:00:00Z'],
            ['antrim-shell', '1.369', '2026-09-15T08:00:00Z'],
            ['antrim-tesco', '1.399', '2026-09-20T07:00:00Z'],
            ['antrim-shell', '1.379', '2026-09-20T07:00:00Z'],
            ['antrim-tesco', '1.399', '2026-09-25T07:00:00Z'],
            ['antrim-shell', '1.379', '2026-09-25T07:00:00Z'],
        ];
        foreach ($prices as [$ref, $price, $at]) {
            $history->record(
                FuelFinderProvider::CODE,
                self::ref($ref),
                new PriceChange(FuelGrade::E10_95, $price, new DateTimeImmutable($at)),
            );
        }
        $this->fillAt($app, $golf, $this->shell, '2026-09-15T10:00:00Z', '11800', '1.369');
        $this->fillAt($app, $golf, $this->shell, '2026-09-20T10:00:00Z', '12400', '1.379');
        $this->fillAt($app, $golf, $this->shell, '2026-09-25T10:00:00Z', '13000', '1.389');

        return [$app, $golf];
    }

    /**
     * A Golf at £0.12/km over 1,000 km and a Polo at £0.05/km over 2,000 km.
     *
     * @param App<ContainerInterface> $app
     * @return array{Vehicle, Vehicle}
     */
    private function twoCars(App $app): array
    {
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-01-01T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '11000', '40', '60.00');
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->fillUp($app, $polo, '2026-01-01T08:00:00Z', '20000', '40', '50.00');
        $this->fillUp($app, $polo, '2026-09-01T08:00:00Z', '22000', '40', '50.00');

        return [$golf, $polo];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function fillAt(App $app, Vehicle $vehicle, Station $station, string $utc, string $km, string $price): void
    {
        $entry = $this->fillUp(
            $app,
            $vehicle,
            $utc,
            $km,
            '40',
            number_format(40 * (float) $price, 2, '.', ''),
            pricePerLitre: $price,
            grade: FuelGrade::E10_95,
        );
        $this->connection($app)->update(
            'fuel_entries',
            ['station_id' => $station->id, 'station' => $station->data->name],
            ['id' => $entry->id],
        );
    }

    /**
     * FinanceTabTest's PCP: 36 × £250 from Jan 2025 and an £8,000 final payment.
     */
    private function pcp(TestBrowser $browser, Vehicle $vehicle): void
    {
        $response = $browser->post('/vehicles/' . $vehicle->id . '/finance/new', [
            'type' => 'pcp',
            'lender' => 'Toyota Financial Services',
            'started_on' => '2024-12-31',
            'first_payment_on' => '2025-01-31',
            'number_of_payments' => '36',
            'regular_payment' => '250',
            'final_payment' => '8000',
            'cash_price' => '20000',
            'customer_deposit' => '2000',
            'dealer_contribution' => '1000',
            'apr' => '0',
            'count_in_costs' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function valuation(App $app, Vehicle $vehicle, string $date, string $amount): void
    {
        $this->service($app, ValuationService::class)->create(
            $vehicle,
            new VehicleValuationData(LocalTime::parseDate($date) ?? self::fail('date'), $amount, 'Dealer'),
        );
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function trip(App $app, Vehicle $vehicle, string $date, string $km): void
    {
        $this->service($app, TripRepository::class)->insert($vehicle->id, new TripData(
            travelledOn: LocalTime::parseDate($date) ?? self::fail('date'),
            fromPlace: 'Office',
            toPlace: 'Client site',
            distanceKm: $km,
            purpose: 'Site visit',
        ), new DateTimeImmutable($date . 'T18:00:00Z'), $this->owner($app)->id);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function allModules(App $app): void
    {
        $this->service($app, FeatureToggles::class)->save(Feature::cases());
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function modulesExcept(App $app, Feature $off): void
    {
        $this->service($app, FeatureToggles::class)->save(
            array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== $off)),
        );
    }

    /**
     * The insights shown, in order, by kind.
     *
     * @return list<string>
     */
    private static function insights(string $html): array
    {
        preg_match_all('/data-insight="([a-z_]+)"/', $html, $matches);

        return $matches[1];
    }
}
