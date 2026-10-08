<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Service\FuelPrices\PriceAlerts;
use Logbook\Service\FuelPrices\StationLinker;
use Logbook\Service\Station\StationService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;

/**
 * The key user's price alerts over the API (Phase 39.1, spec.md §7.34,
 * §7.20): threshold per litre in the provider's currency and whether it
 * is armed; another user's alerts never show; 404 with no provider.
 */
final class ApiPriceAlertsTest extends FuelPricesTestCase
{
    use ApiFixtures;

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    public function testTheKeyUsersAlertsAreListed(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $tesco = $this->service($app, StationLinker::class)->addFromProvider($owner, self::ref('antrim-tesco'));
        $this->service($app, StationService::class)->setFavourite($owner, $tesco, true);
        $this->service($app, PriceAlerts::class)->set($owner, $tesco, FuelGrade::E10_95, '1.369');
        $api = $this->api($app, $this->apiKey($app, $owner));

        $list = ApiClient::json($api->get('/fuel-prices/alerts'));
        self::assertSame([$tesco->id], $list->column('station_id', 'items'));
        self::assertSame('e10_95', $list->get('items', 0, 'grade'));
        self::assertSame('1.369', $list->get('items', 0, 'below'));
        self::assertSame('GBP', $list->get('items', 0, 'currency'));
        self::assertTrue($list->get('items', 0, 'armed'));

        $member = $this->createMember($app, 'partner');
        $theirs = $this->api($app, $this->apiKey($app, $member));
        self::assertSame([], ApiClient::json($theirs->get('/fuel-prices/alerts'))->get('items'));
    }

    public function testWithoutAProviderTheAlertsAreNotFound(): void
    {
        [$app, $owner] = $this->pricesApp(false);
        self::assertSame(404, $this->api($app, $this->apiKey($app, $owner))->get('/fuel-prices/alerts')->getStatusCode());
    }

    public function testAnAlertIsSetChangedAndRemovedAsTheStationPageDoes(): void
    {
        [$app, $owner] = $this->pricesApp();
        $this->sync($app);
        $tesco = $this->service($app, StationLinker::class)->addFromProvider($owner, self::ref('antrim-tesco'));
        $api = $this->api($app, $this->apiKey($app, $owner));
        $body = ['station_id' => $tesco->id, 'grade' => 'e10_95', 'below' => '1.369', 'volume_unit' => 'l'];

        $refused = $api->post('/fuel-prices/alerts', $body);
        self::assertSame(422, $refused->getStatusCode(), 'a favourite only');
        self::assertSame(
            'fuel_prices.alert.refused.not_favourite',
            ApiClient::json($refused)->get('errors', 'station_id', 'key'),
        );
        self::assertSame(204, $api->put('/stations/' . $tesco->id . '/favourite')->getStatusCode());
        self::assertSame(204, $api->put('/stations/' . $tesco->id . '/favourite')->getStatusCode(), 'idempotent');
        self::assertTrue(ApiClient::json($api->get('/stations/' . $tesco->id))->get('favourite'));

        $created = $api->post('/fuel-prices/alerts', $body);
        self::assertSame(201, $created->getStatusCode(), self::body($created));
        $id = ApiClient::json($created)->int('entry', 'id');
        self::assertSame('1.369', ApiClient::json($created)->get('entry', 'below'));
        $again = $api->post('/fuel-prices/alerts', ['below' => '1.359'] + $body);
        self::assertSame(200, $again->getStatusCode(), 'that station and grade: changed, as the form');
        self::assertTrue(ApiClient::json($again)->get('duplicate'));
        self::assertSame($id, ApiClient::json($again)->int('entry', 'id'));

        $gallon = ApiClient::json($api->patch('/fuel-prices/alerts/' . $id, ['below' => '6.2', 'volume_unit' => 'gal_uk']));
        self::assertSame('1.364', $gallon->get('entry', 'below'), '6.2 per UK gallon, per litre');
        self::assertSame(422, $api->patch('/fuel-prices/alerts/' . $id, ['below' => '0'])->getStatusCode());
        self::assertSame(422, $api->patch('/fuel-prices/alerts/' . $id, ['grade' => 'e5_97'])->getStatusCode());
        self::assertSame(422, $api->post('/fuel-prices/alerts', ['grade' => 'nonsense'] + $body)->getStatusCode());

        $member = $this->createMember($app, 'partner');
        $theirs = $this->api($app, $this->apiKey($app, $member));
        self::assertSame(404, $theirs->patch('/fuel-prices/alerts/' . $id, ['below' => '1'])->getStatusCode());
        self::assertSame(404, $theirs->delete('/fuel-prices/alerts/' . $id)->getStatusCode());

        self::assertSame(204, $api->delete('/fuel-prices/alerts/' . $id)->getStatusCode());
        self::assertSame([], ApiClient::json($api->get('/fuel-prices/alerts'))->get('items'));
        self::assertSame(404, $api->delete('/fuel-prices/alerts/' . $id)->getStatusCode());

        self::assertSame(201, $api->post('/fuel-prices/alerts', $body)->getStatusCode());
        self::assertSame(204, $api->delete('/stations/' . $tesco->id . '/favourite')->getStatusCode());
        $alerts = ApiClient::json($api->get('/fuel-prices/alerts'))->get('items');
        self::assertSame([], $alerts, 'unstarring removes its alerts');
        self::assertSame(204, $api->delete('/stations/' . $tesco->id . '/favourite')->getStatusCode(), 'idempotent');
        self::assertSame(404, $api->put('/stations/999999/favourite')->getStatusCode());
    }

    public function testWithoutAProviderAlertWritesAreNotFound(): void
    {
        [$app, $owner] = $this->pricesApp(false);
        $api = $this->api($app, $this->apiKey($app, $owner));

        $body = ['station_id' => 1, 'grade' => 'e10_95', 'below' => '1'];
        self::assertSame(404, $api->post('/fuel-prices/alerts', $body)->getStatusCode());
        self::assertSame(404, $api->patch('/fuel-prices/alerts/1', ['below' => '1'])->getStatusCode());
        self::assertSame(404, $api->delete('/fuel-prices/alerts/1')->getStatusCode());
    }
}
