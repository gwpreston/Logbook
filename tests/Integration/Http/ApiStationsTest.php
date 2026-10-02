<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Stations in the API (spec.md §7.20, §7.33): fill-ups keep `station` and
 * gain `station_id` (#135); writes take either; the station endpoints carry
 * what the key's user paid, and never their places. ApiClient checks every
 * response against openapi.json.
 */
final class ApiStationsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function fill(array $body = []): array
    {
        return $body + [
            'filled_at' => '2026-09-29T07:42:00Z',
            'odometer' => '1000',
            'distance_unit' => 'km',
            'volume' => '40',
            'price_per_unit' => '1.389',
            'grade' => 'e10_95',
        ];
    }

    public function testAFillUpLinksByNameOrIdAndReadsBoth(): void
    {
        $fuel = '/vehicles/' . $this->golf->id . '/fuel';
        $created = $this->api->post($fuel, self::fill(['station' => 'Tesco  antrim']));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $entry = ApiClient::json($created);
        $id = $entry->int('entry', 'station_id');
        self::assertSame('Tesco antrim', $entry->string('entry', 'station'));

        $second = $this->api->post($fuel, self::fill(['station_id' => $id, 'odometer' => '1500', 'filled_at' => '2026-09-30T07:00:00Z']));
        self::assertSame(201, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame($id, ApiClient::json($second)->int('entry', 'station_id'));

        $list = ApiClient::json($this->api->get($fuel));
        self::assertSame([$id, $id], $list->column('station_id', 'items'));

        $both = $this->api->post($fuel, self::fill(['station' => 'Shell', 'station_id' => $id, 'odometer' => '2000']));
        self::assertSame(422, $both->getStatusCode());
        self::assertStringContainsString('station_id', (string) $both->getBody());
        $unknown = $this->api->post($fuel, self::fill(['station_id' => 999999, 'odometer' => '2000']));
        self::assertSame(422, $unknown->getStatusCode());
        self::assertStringContainsString('No such station.', (string) $unknown->getBody());
        $fraction = $this->api->post($fuel, self::fill(['station_id' => 1.5, 'odometer' => '2000']));
        self::assertSame(422, $fraction->getStatusCode(), 'a whole number');
        self::assertCount(1, $this->service($this->app, StationRepository::class)->listActive());
    }

    public function testTheStationEndpointsCarryWhatWasPaidAndNoPlaces(): void
    {
        $stations = $this->service($this->app, StationRepository::class);
        $now = new DateTimeImmutable('2026-09-30T12:00:00Z');
        $tesco = $stations->insert(new StationData('Tesco Antrim', 'Tesco', latitude: '54.715400', longitude: '-6.216400'), $this->owner->id, $now);
        $maxol = $stations->insert(new StationData('Maxol Antrim'), $this->owner->id, $now);
        $stations->setFavourite($this->owner->id, $maxol, true, $now);
        $this->service($this->app, PlaceRepository::class)->insert($this->owner->id, new PlaceData('Home', '54.7', '-6.2'), $now);
        $this->api->post('/vehicles/' . $this->golf->id . '/fuel', self::fill(['station_id' => $tesco]));

        $list = ApiClient::json($this->api->get('/stations'));
        self::assertSame(['Maxol Antrim', 'Tesco Antrim'], $list->column('name', 'items'), 'favourites first');
        self::assertSame(1, $list->int('items', 1, 'visits'));
        self::assertSame('1.389000', $list->string('items', 1, 'paid', 0, 'average_price'));
        self::assertSame(['Maxol Antrim'], ApiClient::json($this->api->get('/stations?favourites=true'))->column('name', 'items'));
        self::assertSame(['Tesco Antrim'], ApiClient::json($this->api->get('/stations?q=tesco'))->column('name', 'items'));

        $show = $this->api->get('/stations/' . $tesco);
        self::assertSame(200, $show->getStatusCode());
        self::assertStringNotContainsString('Home', (string) $show->getBody(), 'places are never in the API');

        $stations->merge($maxol, $tesco, $now);
        self::assertSame($tesco, ApiClient::json($this->api->get('/stations/' . $maxol))->int('id'), 'a merged id resolves');
        self::assertSame(404, $this->api->get('/stations/999999')->getStatusCode());
    }

    public function testWithStationsOffTheEndpointsAreGoneAndLinksStay(): void
    {
        $features = $this->service($this->app, FeatureToggles::class);
        $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Stations)));

        self::assertSame(404, $this->api->get('/stations')->getStatusCode());
        self::assertFalse(ApiClient::json($this->api->get('/me'))->get('modules', 'stations'));
        $created = $this->api->post('/vehicles/' . $this->golf->id . '/fuel', self::fill(['station' => 'Tesco Antrim']));
        self::assertSame(201, $created->getStatusCode());
        self::assertNull(ApiClient::json($created)->get('entry', 'station_id'), 'nothing is created while off');
        self::assertSame([], $this->service($this->app, StationRepository::class)->listActive());
        self::assertSame('Tesco Antrim', $this->service($this->app, FuelEntryRepository::class)->listForVehicle($this->golf->id)[0]->data->station);
    }
}
