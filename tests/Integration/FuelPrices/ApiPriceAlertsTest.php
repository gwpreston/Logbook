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
}
