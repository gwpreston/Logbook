<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use Logbook\Repository\FuelPriceSecretRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\FuelPrices\FuelPriceConfig;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\FuelPrices\Uk\FuelFinderProvider;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\JsonDoc;

/**
 * The fuel price pages, API and settings (spec.md §7.34): nothing appears
 * or is fetched while no provider is enabled; Settings → Fuel prices is for
 * admins only and never shows a credential back; Cheapest near me, the
 * station page, the fill-up hint, the widget and the API with prices on; a
 * position sent with a search is never stored.
 */
final class FuelPricePagesTest extends FuelPricesTestCase
{
    use ApiFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testWithNoProviderNothingAboutPricesAppears(): void
    {
        [$app, $owner] = $this->pricesApp(enable: false);
        $this->home($app, $owner);
        $station = $this->station($app, 'Tesco Antrim', '54.718012', '-6.219034');
        $browser = $this->browserFor($app, 'owner');

        self::assertSame(404, $browser->get('/stations/near')->getStatusCode());
        $page = (string) $browser->get('/stations/' . $station->id)->getBody();
        self::assertStringNotContainsString('data-listed-prices', $page);
        self::assertStringNotContainsString('data-station-link', $page);
        self::assertStringNotContainsString('data-cheapest-near', (string) $browser->get('/stations')->getBody());
        self::assertStringNotContainsString('widget-cheapest_fuel', (string) $browser->get('/')->getBody());
        self::assertStringContainsString('Off: choose a provider', (string) $browser->get('/settings')->getBody());

        $api = $this->api($app, $this->apiKey($app, $owner));
        self::assertSame(404, $api->get('/fuel-prices/near?place=Home')->getStatusCode());
        self::assertNull(ApiClient::json($api->get('/stations/' . $station->id))->get('listed'));
        self::assertSame([], $this->requests, 'nothing was fetched');
    }

    public function testSettingsAreForAdminsAndNeverShowACredentialBack(): void
    {
        [$app] = $this->pricesApp(enable: false);
        $this->createMember($app);
        self::assertSame(404, $this->browserFor($app, 'partner')->get('/settings/fuel-prices')->getStatusCode());

        $browser = $this->browserFor($app, 'owner');
        self::assertSame(200, $browser->get('/settings/fuel-prices')->getStatusCode());

        // Enabling needs the credentials.
        $refused = $browser->post(
            '/settings/fuel-prices',
            ['provider' => FuelFinderProvider::CODE, 'refresh' => '60', 'e5' => 'e5_97'],
        );
        self::assertSame(200, $refused->getStatusCode());
        self::assertStringContainsString(
            'Enter the provider&#039;s credentials before enabling it.',
            (string) $refused->getBody(),
        );
        self::assertFalse($this->service($app, FuelPriceConfig::class)->enabled());

        $saved = $browser->post('/settings/fuel-prices', [
            'provider' => FuelFinderProvider::CODE,
            'refresh' => '30',
            'e5' => 'e5_98',
            'secret' => ['client_id' => 'env:FF_ID', 'client_secret' => 'a-real-secret-value-1234'],
        ]);
        self::assertSame(303, $saved->getStatusCode());
        $config = $this->service($app, FuelPriceConfig::class);
        self::assertTrue($config->enabled());
        self::assertSame(30, $config->settings()->refresh);
        self::assertSame('e5_98', $config->settings()->e5->value);

        $stored = $this->service($app, FuelPriceSecretRepository::class)->forProvider(FuelFinderProvider::CODE);
        self::assertSame('env:FF_ID', $stored['client_id']);
        self::assertStringStartsWith('v1:', $stored['client_secret'], 'sealed');
        $page = (string) $browser->get('/settings/fuel-prices')->getBody();
        self::assertStringNotContainsString('a-real-secret-value-1234', $page);
        self::assertStringContainsString('Read from the environment variable FF_ID.', $page);
        self::assertStringContainsString('Saved. Type a new value to replace it.', $page);
        self::assertStringContainsString('Sync now', $page);
    }

    /**
     * Phase 37: the provider options are the fieldset's own children, as on
     * Settings → Jobs, so `.fieldset > .toggle + .toggle` spaces them; a
     * wrapper between them would let their borders touch again.
     */
    public function testProviderOptionsSitDirectlyInTheirFieldset(): void
    {
        [$app] = $this->pricesApp(enable: false);
        $document = Html::document((string) $this->browserFor($app, 'owner')->get('/settings/fuel-prices')->getBody());

        $all = $document->querySelectorAll('input[name="provider"]');
        $spaced = $document->querySelectorAll('fieldset.card.fieldset > label.toggle > input[name="provider"]');
        self::assertGreaterThanOrEqual(2, $all->length);
        self::assertSame($all->length, $spaced->length);
        Html::element($document, 'fieldset.card.fieldset[aria-describedby~="provider-hint"] > #provider-hint');
    }

    public function testCheapestNearMeFromTheCurrentPositionStoresNothing(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $golf = $this->vehicle($app);
        $browser = $this->browserFor($app, 'owner');

        $response = $browser->get(
            '/stations/near?from=here&lat=54.716123&lng=-6.208456&vehicle=' . $golf->id . '&grade=e10_95&radius=5',
        );
        self::assertSame(200, $response->getStatusCode());
        $page = (string) $response->getBody();
        self::assertStringContainsString('Tesco Antrim Extra', $page);
        self::assertStringContainsString('Effective cost counts the fuel to get there and back', $page);
        self::assertStringContainsString('Contains public sector information licensed under the', $page);
        self::assertStringContainsString('Add station', $page);
        self::assertStringNotContainsString('Maxol', $page, 'temporarily closed');

        // The position went no further than this answer.
        $connection = $this->connection($app);
        foreach (['settings', 'sessions', 'job_runs'] as $table) {
            foreach ($connection->fetchAllAssociative('SELECT * FROM ' . $table) as $row) {
                self::assertStringNotContainsString('54.716123', (string) json_encode($row), $table);
            }
        }

        // Without a position or a place, the page asks for one.
        self::assertStringContainsString('data-needs-position', (string) $browser->get('/stations/near?from=here')->getBody());
    }

    public function testTheStationPageFillUpHintAndWidgetShowListedPrices(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $this->home($app, $owner);
        $golf = $this->vehicle($app);
        $tesco = $this->service($app, StationLinker::class)->addFromProvider($owner, self::ref('antrim-tesco'));
        $browser = $this->browserFor($app, 'owner');

        $page = (string) $browser->get('/stations/' . $tesco->id)->getBody();
        self::assertStringContainsString('data-listed-prices', $page);
        self::assertStringContainsString('Listed £1.359/L at', $page);
        self::assertStringContainsString('Make this station a favourite to set price alerts.', $page);
        self::assertStringContainsString('Linked to Tesco Antrim Extra in the price feed.', $page);

        $browser->post('/stations/' . $tesco->id . '/favourite', ['favourite' => '1']);
        $browser->post('/stations/' . $tesco->id . '/alerts', ['grade' => 'e10_95', 'below' => '1.349']);
        self::assertStringContainsString(
            'data-alert-state="armed"',
            (string) $browser->get('/stations/' . $tesco->id)->getBody(),
        );

        $search = new JsonDoc(json_decode((string) $browser->get('/stations/search?q=Tesco')->getBody(), true));
        self::assertSame('1.359', $search->string('results', 0, 'listed', 'e10_95', 'price'));
        self::assertStringStartsWith('Listed £1.359/L E10 95 at ', $search->string('results', 0, 'listed', 'e10_95', 'text'));

        $dashboard = (string) $browser->get('/')->getBody();
        self::assertStringContainsString('widget-cheapest_fuel', $dashboard);
        self::assertStringContainsString('Cheapest E10 95 near Home for Volkswagen Golf', $dashboard);
        self::assertSame(1, $golf->id > 0 ? 1 : 0);
    }

    public function testTheApiAnswersFromAPlaceAPositionOrAStation(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $this->home($app, $owner);
        $this->vehicle($app);
        $tesco = $this->service($app, StationLinker::class)->addFromProvider($owner, self::ref('antrim-tesco'));
        $api = $this->api($app, $this->apiKey($app, $owner));

        $near = ApiClient::json($api->get('/fuel-prices/near?place=home&grade=e10_95'));
        self::assertSame('place', $near->string('origin', 'kind'));
        self::assertSame('Tesco Antrim Extra', $near->string('items', 0, 'name'));
        self::assertSame($tesco->id, $near->int('items', 0, 'station_id'));
        self::assertSame('1.359', $near->string('items', 0, 'listed', 'price'));
        self::assertTrue($near->get('items', 0, 'nearest'));
        self::assertSame('uk_fuel_finder', $near->string('provider', 'code'));

        $here = ApiClient::json($api->get('/fuel-prices/near?lat=54.7181&lng=-6.2191&radius=1'));
        self::assertSame('here', $here->string('origin', 'kind'));
        self::assertNull($here->get('origin', 'label'));
        self::assertSame(1, count((array) $here->get('items')));

        self::assertSame(200, $api->get('/fuel-prices/near?station=' . $tesco->id)->getStatusCode());
        self::assertSame(400, $api->get('/fuel-prices/near?place=Home&lat=1&lng=1')->getStatusCode(), 'one origin only');
        self::assertSame(400, $api->get('/fuel-prices/near?place=Office')->getStatusCode());
        self::assertSame(400, $api->get('/fuel-prices/near?place=Home&grade=e85')->getStatusCode());

        // Another user's vehicle is not found.
        $partner = $this->createMember($app);
        $theirs = $this->service($app, \Logbook\Service\Vehicle\VehicleService::class)->create(
            $partner,
            new \Logbook\Domain\Vehicle\VehicleData(
                \Logbook\Domain\Vehicle\VehicleType::Car,
                'Ford',
                'Fiesta',
                \Logbook\Domain\Vehicle\FuelType::Petrol,
            ),
        );
        self::assertSame(404, $api->get('/fuel-prices/near?place=Home&vehicle=' . $theirs->id)->getStatusCode());

        $station = ApiClient::json($api->get('/stations/' . $tesco->id));
        self::assertSame('e10_95', $station->string('listed', 0, 'grade'));
        self::assertNotNull($this->service($app, StationRepository::class)->find($tesco->id)?->link);
    }
}
