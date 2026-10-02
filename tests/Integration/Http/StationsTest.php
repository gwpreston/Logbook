<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\PlaceRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Fuel stations end to end (spec.md §7.33, Phase 30.1): the fill-up form
 * links or creates stations (with and without JS), the combo box's order
 * and hint, home charging, the station pages and what they count, merging
 * and duplicates, places and their privacy, and the module switches.
 */
final class StationsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-20T10:00:00Z';

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function fill(array $overrides = []): array
    {
        return $overrides + [
            'filled_at' => '2026-09-19T08:00',
            'odometer' => '1000',
            'fuel' => 'petrol:e10_95',
            'volume' => '40',
            'price' => '1.389',
            'total' => '',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function station(App $app, string $name, ?int $by = null, ?string $lat = null, ?string $lon = null): Station
    {
        $repository = $this->service($app, StationRepository::class);
        $id = $repository->insert(
            new StationData($name, latitude: $lat, longitude: $lon),
            $by ?? $this->owner($app)->id,
            new DateTimeImmutable(self::NOW),
        );

        return $repository->find($id) ?? self::fail('station not saved');
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<?int>
     */
    private function links(App $app, int $vehicleId): array
    {
        return array_map(
            static fn ($entry): ?int => $entry->data->stationId,
            $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicleId),
        );
    }

    public function testTypingANameCreatesTheStationAndTheSameNameLinksIt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $form = self::body($browser->get('/vehicles/' . $golf->id . '/fuel/new'));
        self::assertStringContainsString('name="station_id"', $form, 'the no-JS select');
        self::assertStringContainsString('data-station-field', $form, 'the combo box hook');
        self::assertStringContainsString('value="other"', $form);

        $new = '/vehicles/' . $golf->id . '/fuel/new';
        $response = $browser->post($new, self::fill(['station_id' => 'other', 'station' => '  Tesco   Antrim ']));
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $stations = $this->service($app, StationRepository::class)->listActive();
        self::assertCount(1, $stations);
        self::assertSame('Tesco Antrim', $stations[0]->data->name, 'tidied');
        self::assertSame($this->owner($app)->id, $stations[0]->createdBy);
        self::assertSame('GB', $stations[0]->data->country, 'the creator\'s region');

        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill([
            'odometer' => '1500', 'filled_at' => '2026-09-20T08:00', 'station' => 'TESCO antrim', 'price' => '1.409',
        ]));
        self::assertCount(1, $this->service($app, StationRepository::class)->listActive(), 'the same normalised name links');
        self::assertSame([$stations[0]->id, $stations[0]->id], $this->links($app, $golf->id));
        $entries = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertSame('Tesco Antrim', $entries[1]->data->station, 'the text is the station\'s name');

        // The select now offers it as recent, with the hint once chosen.
        $edit = self::body($browser->get('/vehicles/' . $golf->id . '/fuel/' . $entries[1]->id . '/edit'));
        self::assertMatchesRegularExpression('/<option value="' . $stations[0]->id . '"[^>]*selected/', $edit);
        self::assertStringContainsString('Last time here: £1.409/L E10 95, 20 Sept 2026', $edit);
    }

    public function testChoosingAStationByIdAndClearingIt(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $shell = $this->station($app, 'Shell Larne');
        $old = $this->station($app, 'Shell Larne (old)');
        $this->service($app, StationRepository::class)->merge($old->id, $shell->id, new DateTimeImmutable(self::NOW));

        $new = '/vehicles/' . $golf->id . '/fuel/new';
        $browser->post($new, self::fill(['station_id' => (string) $old->id, 'station' => 'ignored']));
        self::assertSame([$shell->id], $this->links($app, $golf->id), 'a merged id resolves to the station it became');
        $entry = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame('Shell Larne', $entry->data->station);

        $edit = '/vehicles/' . $golf->id . '/fuel/' . $entry->id . '/edit';
        $browser->post($edit, self::fill(['station_id' => '', 'station' => '']));
        self::assertSame([null], $this->links($app, $golf->id), 'no station');
        $bad = $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill(['station_id' => 'x1']));
        self::assertSame(422, $bad->getStatusCode(), 'a station id that is not a number');
    }

    public function testHomeChargingIsNeverAStation(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $kona = $this->vehicle($app, 'Hyundai', 'Kona', fuel: FuelType::Electric);
        $shell = $this->station($app, 'Home');

        $response = $browser->post('/vehicles/' . $kona->id . '/fuel/new', self::fill([
            'fuel' => 'ev:home', 'volume' => '30', 'price' => '0.245', 'station_id' => (string) $shell->id, 'station' => 'Home',
        ]));
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertSame([null], $this->links($app, $kona->id));
        self::assertSame('Home', $this->service($app, FuelEntryRepository::class)->listForVehicle($kona->id)[0]->data->station);
        self::assertCount(1, $this->service($app, StationRepository::class)->listActive(), 'nothing created');

        $browser->post('/vehicles/' . $kona->id . '/fuel/new', self::fill([
            'fuel' => 'ev:dc_rapid', 'odometer' => '1200', 'filled_at' => '2026-09-19T12:00', 'volume' => '30', 'price' => '0.79',
            'station_id' => 'other', 'station' => 'Ionity Antrim',
        ]));
        self::assertNotNull($this->links($app, $kona->id)[1], 'a public charger is a station');
    }

    public function testTheSearchOrdersFavouritesThenRecentThenTheRest(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $apple = $this->station($app, 'Applegreen Antrim');
        $tesco = $this->station($app, 'Tesco Antrim');
        $maxol = $this->station($app, 'Maxol Antrim');
        $this->station($app, 'Shell Larne');
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill(['station_id' => (string) $tesco->id]));
        $browser->post('/stations/' . $maxol->id . '/favourite', ['favourite' => '1']);

        $answer = ApiClient::json($browser->get('/stations/search?q=antrim'));
        self::assertSame(['Maxol Antrim', 'Tesco Antrim', 'Applegreen Antrim'], $answer->column('name', 'results'));
        self::assertTrue($answer->get('results', 0, 'favourite'));
        self::assertTrue($answer->get('results', 1, 'recent'));
        self::assertSame('Last time here: £1.389/L E10 95, 19 Sept 2026', $answer->string('results', 1, 'hint'));
        self::assertNull($answer->get('exact'), 'no station is called "antrim": Add is offered');

        $exact = ApiClient::json($browser->get('/stations/search?q=' . rawurlencode(' applegreen  ANTRIM')));
        self::assertSame($apple->id, $exact->int('exact'));

        $empty = ApiClient::json($browser->get('/stations/search'));
        self::assertSame(['Maxol Antrim', 'Tesco Antrim'], $empty->column('name', 'results'), 'favourites and recent only');
    }

    public function testTheStationPageCountsOnlyWhatTheUserCanSee(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $tesco = $this->station($app, 'Tesco Antrim', null, '54.715400', '-6.216400');
        $at = (string) $tesco->id;
        $golfFuel = '/vehicles/' . $golf->id . '/fuel/new';
        $browser->post($golfFuel, self::fill(['station_id' => $at, 'volume' => '10', 'price' => '1.5']));
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill([
            'station_id' => $at, 'odometer' => '1400', 'filled_at' => '2026-09-19T18:00', 'volume' => '30', 'price' => '1.4',
        ]));
        $browser->post('/vehicles/' . $polo->id . '/fuel/new', self::fill(['station_id' => $at, 'price' => '1.2']));

        $page = self::body($browser->get('/stations/' . $tesco->id));
        self::assertStringContainsString('3 visits', $page);
        // Golf and Polo: (10 × 1.5 + 30 × 1.4 + 40 × 1.2) / 80 = 1.3125.
        self::assertStringContainsString('£1.313/L', $page, 'weighted by volume');
        self::assertStringContainsString('£1.200/L', $page, 'the cheapest');
        self::assertStringContainsString('openstreetmap.org', $page);
        self::assertStringContainsString('data-chart=', $page);

        $fuelTab = self::body($browser->get('/vehicles/' . $golf->id . '/fuel'));
        self::assertStringContainsString('By station', $fuelTab);
        self::assertStringContainsString('/stations/' . $tesco->id, $fuelTab);
        // The Golf alone: (10 × 1.5 + 30 × 1.4) / 40 = 1.425.
        self::assertStringContainsString('£1.425/L', $fuelTab);

        // A member who sees only the Golf, without costs.
        $member = $this->createMember($app, 'partner');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::View, false, false, new DateTimeImmutable(self::NOW));
        $theirs = self::body($this->browserFor($app, 'partner')->get('/stations/' . $tesco->id));
        self::assertStringContainsString('2 visits', $theirs);
        self::assertStringNotContainsString('£1.', $theirs, 'no amounts without costs');
        self::assertStringNotContainsString('Polo', $theirs);
        self::assertStringNotContainsString('/stations/' . $tesco->id . '/edit', $theirs, 'only the creator or an admin edits');
        self::assertSame(403, $this->browserFor($app, 'partner')->get('/stations/' . $tesco->id . '/edit')->getStatusCode());
        $theirTab = self::body($this->browserFor($app, 'partner')->get('/vehicles/' . $golf->id . '/fuel'));
        self::assertStringNotContainsString('By station', $theirTab, 'the card needs ViewCosts');
    }

    public function testMergingMovesFillUpsAndFavouritesAndOldLinksResolve(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $a = $this->station($app, 'Tesco Antrim');
        $b = $this->station($app, 'Tesco Antrim.', null, '54.715400', '-6.216400');
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill(['station_id' => (string) $a->id]));
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill([
            'station_id' => (string) $b->id, 'odometer' => '1400', 'filled_at' => '2026-09-19T18:00',
        ]));
        $browser->post('/stations/' . $a->id . '/favourite', ['favourite' => '1']);
        $browser->post('/stations/' . $b->id . '/favourite', ['favourite' => '1']);

        $duplicates = self::body($browser->get('/stations/duplicates'));
        self::assertStringContainsString('names one letter apart', $duplicates);
        self::assertStringContainsString('/stations/' . $a->id . '/merge?with=' . $b->id, $duplicates);

        $form = self::body($browser->get('/stations/' . $a->id . '/merge?with=' . $b->id));
        self::assertStringContainsString('name="field_name"', $form, 'both have a name: choose');
        self::assertStringNotContainsString('name="field_position"', $form, 'only one has a position: kept');

        $response = $browser->post('/stations/' . $a->id . '/merge', [
            'with' => (string) $b->id, 'keep' => 'this', 'confirm' => '1', 'field_name' => 'keep',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $stations = $this->service($app, StationRepository::class);
        self::assertSame([$a->id, $a->id], $this->links($app, $golf->id));
        self::assertSame([$a->id], $stations->favouriteIds($this->owner($app)->id), 'one favourite kept');
        self::assertSame($a->id, $stations->find($b->id)?->mergedInto);
        $kept = $stations->find($a->id);
        self::assertSame('Tesco Antrim', $kept?->data->name);
        self::assertSame('54.715400', $kept->data->latitude, 'the other\'s position where the kept one had none');

        $old = $browser->get('/stations/' . $b->id);
        self::assertSame(303, $old->getStatusCode());
        self::assertStringEndsWith('/stations/' . $a->id, $old->getHeaderLine('Location'));
        self::assertStringNotContainsString('names one letter apart', self::body($browser->get('/stations/duplicates')));

        // The merged spelling, typed again, links the kept station.
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill([
            'odometer' => '1800', 'filled_at' => '2026-09-20T08:00', 'station_id' => 'other', 'station' => 'tesco antrim.',
        ]));
        self::assertSame([$a->id, $a->id, $a->id], $this->links($app, $golf->id));
        self::assertCount(1, $stations->listActive(), 'no new duplicate');
    }

    public function testPlacesGiveDistancesAndArePrivate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $tesco = $this->station($app, 'Tesco Antrim', null, '54.715400', '-6.216400');

        $response = $browser->post('/settings/places/new', ['name' => 'Home', 'latitude' => '54,7064', 'longitude' => '-6.2164']);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        $list = self::body($browser->get('/stations'));
        // 0.009 degrees of latitude: 1.0 km, 0.6 mi.
        self::assertStringContainsString('0.6 mi from Home', $list);
        self::assertStringContainsString('(in a straight line)', $list);

        $bad = $browser->post('/settings/places/new', ['name' => 'Work', 'latitude' => '95', 'longitude' => '']);
        self::assertSame(422, $bad->getStatusCode());

        // Never in print views, the sale pack or the API's vehicle data.
        $browser->post('/settings/places/new', ['name' => 'Grans Cottage', 'latitude' => '54.8', 'longitude' => '-6.3']);
        $golf = $this->vehicle($app);
        $fill = self::fill(['station_id' => (string) $tesco->id]);
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', $fill);
        foreach (['/history/print', '/sale-pack', '/sale-pack?options=1', ''] as $suffix) {
            $page = self::body($browser->get('/vehicles/' . $golf->id . $suffix));
            self::assertStringNotContainsString('Grans Cottage', $page, $suffix);
            self::assertStringNotContainsString('54.706400', $page, $suffix);
        }

        $member = $this->createMember($app, 'partner');
        $other = $this->browserFor($app, 'partner');
        self::assertStringNotContainsString('from Home', self::body($other->get('/stations')));
        self::assertStringNotContainsString('from Home', self::body($other->get('/stations/' . $tesco->id)));
        $place = $this->service($app, PlaceRepository::class)->listForUser($this->owner($app)->id)[0];
        self::assertSame(404, $other->get('/settings/places/' . $place->id . '/edit')->getStatusCode());
        self::assertSame(404, $other->post('/settings/places/' . $place->id . '/delete')->getStatusCode());
        self::assertSame([], $this->service($app, PlaceRepository::class)->listForUser($member->id));
    }

    public function testCurrentLocationIsOptionalAndNothingIsStoredUntilSaved(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $form = self::body($browser->get('/settings/places/new'));
        self::assertStringContainsString('data-locate', $form);
        self::assertMatchesRegularExpression('/data-locate[^>]* hidden/', $form, 'hidden until the script finds geolocation');
        self::assertSame([], $this->service($app, PlaceRepository::class)->listForUser($this->owner($app)->id));

        $station = self::body($browser->get('/stations/new'));
        self::assertStringContainsString('data-locate', $station);
        $response = $browser->post('/stations/new', ['name' => 'Maxol Antrim']);
        self::assertSame(303, $response->getStatusCode(), 'no position is needed');
    }

    public function testSwitchingStationsOrFuelOffRemovesEverythingAndKeepsTheData(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $tesco = $this->station($app, 'Tesco Antrim');
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill(['station_id' => (string) $tesco->id]));
        $this->service($app, PlaceRepository::class)
            ->insert($this->owner($app)->id, new PlaceData('Home', '54.7', '-6.2'), new DateTimeImmutable(self::NOW));
        $features = $this->service($app, FeatureToggles::class);

        foreach ([Feature::Stations, Feature::Fuel] as $off) {
            $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== $off)));
            self::assertFalse($features->isEnabled(Feature::Stations), $off->value . ' off');
            $paths = ['/stations', '/stations/' . $tesco->id, '/stations/search?q=t', '/stations/duplicates', '/settings/places'];
            foreach ($paths as $path) {
                self::assertSame(404, $browser->get($path)->getStatusCode(), $path . ' with ' . $off->value . ' off');
            }
            self::assertStringNotContainsString('href="/stations"', self::body($browser->get('/')), 'not in the navigation');
        }

        $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Stations)));
        $form = self::body($browser->get('/vehicles/' . $golf->id . '/fuel/new'));
        self::assertStringNotContainsString('name="station_id"', $form, 'the plain text field');
        $entry = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id)[0];
        $edit = '/vehicles/' . $golf->id . '/fuel/' . $entry->id . '/edit';
        $browser->post($edit, self::fill(['station' => 'Tesco Antrim', 'notes' => 'edited']));
        self::assertSame([$tesco->id], $this->links($app, $golf->id), 'the link is kept while the text is unchanged');
        $browser->post('/vehicles/' . $golf->id . '/fuel/new', self::fill(['odometer' => '1500', 'station' => 'Elsewhere']));
        self::assertCount(1, $this->service($app, StationRepository::class)->listActive(), 'nothing created while off');

        $features->save(Feature::cases());
        self::assertSame(200, $browser->get('/stations/' . $tesco->id)->getStatusCode(), 'back as it was');
        self::assertCount(1, $this->service($app, PlaceRepository::class)->listForUser($this->owner($app)->id), 'places kept');
    }

    public function testTheModulesPageKeepsTheStationsSwitchWhileFuelIsOff(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $features = $this->service($app, FeatureToggles::class);
        $features->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Fuel)));

        $page = self::body($browser->get('/settings/modules'));
        self::assertMatchesRegularExpression('/name="stations" value="1" checked/', $page, 'its own switch');
        self::assertStringContainsString('Part of Fuel: off while it is off.', $page);
        self::assertFalse($features->isEnabled(Feature::Stations));
        self::assertTrue($features->own()['stations']);
    }
}
