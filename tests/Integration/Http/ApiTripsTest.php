<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Api\ApiScope;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\TripRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Trip\SavedJourneyService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The trips endpoints (spec.md §7.20, §7.22, §7.23): the list follows who
 * may see whose trips, a POST goes through the trip form's parser in km
 * (the whole trip), fills from a saved journey and is safe to retry, and
 * the claim carries the report's figures. ApiClient checks every response
 * against openapi.json.
 */
final class ApiTripsTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $trips;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        // en_GB, miles, Europe/London: HMRC rates and a 6 April tax year.
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->trips = '/vehicles/' . $this->golf->id . '/trips';
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
    private static function trip(array $body = []): array
    {
        return $body + [
            'travelled_on' => '2026-09-29',
            'from' => 'Ballymena',
            'to' => 'Belfast',
            'distance_km' => '45.2',
            'purpose' => 'Client visit',
        ];
    }

    private function storedTrips(): int
    {
        return count($this->service($this->app, TripRepository::class)->listForVehicle($this->golf->id));
    }

    private function journey(User $user, SavedJourneyData $data): SavedJourney
    {
        return $this->service($this->app, SavedJourneyService::class)->create($user, $data);
    }

    public function testAPostLogsTheTripAsTheFormStoresItAndARetryWritesNothing(): void
    {
        $response = $this->api->post($this->trips, self::trip([
            'is_return' => true,
            'distance_km' => 90.4,
            'odometer_start_km' => '10000',
            'odometer_end_km' => '10090.4',
            'passengers' => 1,
            'notes' => 'Parking at the office',
        ]));

        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $body = ApiClient::json($response);
        self::assertFalse($body->get('duplicate'));
        self::assertSame([], $body->get('warnings'));
        $entry = $body->doc('entry');
        self::assertSame([
            'vehicle_id' => $this->golf->id,
            'travelled_on' => '2026-09-29',
            'from' => 'Ballymena',
            'to' => 'Belfast',
            'journey' => 'Ballymena → Belfast → Ballymena',
            'is_return' => true,
            // The whole trip, as sent: never doubled.
            'distance_km' => '90.400',
            'odometer_start_km' => '10000.000',
            'odometer_end_km' => '10090.400',
            'is_business' => true,
            'purpose' => 'Client visit',
            'passengers' => 1,
            'notes' => 'Parking at the office',
            'created_by' => $this->owner->id,
        ], array_diff_key($entry->toArray(), array_flip(['id', 'created_at', 'updated_at'])));
        self::assertSame('2026-09-30T12:00:00Z', $entry->get('created_at'));

        // A retry (same day, places and distance) is the same trip.
        $retry = $this->api->post($this->trips, self::trip(['is_return' => true, 'distance_km' => '90.400']));
        self::assertSame(200, $retry->getStatusCode(), self::body($retry));
        self::assertTrue(ApiClient::json($retry)->get('duplicate'));
        self::assertSame($entry->get('id'), ApiClient::json($retry)->get('entry', 'id'));
        self::assertSame(1, $this->storedTrips());

        // Defaults: today in the owner's zone, business, one way, no odometers.
        $plain = ApiClient::json(
            $this->api->post($this->trips, ['from' => 'Home', 'to' => 'Depot', 'distance_km' => '0', 'purpose' => 'x']),
        );
        self::assertSame(
            ['2026-09-30', true, false, '0.000', null, 0],
            [
                $plain->get('entry', 'travelled_on'),
                $plain->get('entry', 'is_business'),
                $plain->get('entry', 'is_return'),
                $plain->get('entry', 'distance_km'),
                $plain->get('entry', 'odometer_start_km'),
                $plain->get('entry', 'passengers'),
            ],
        );
        $private = ApiClient::json(
            $this->api->post($this->trips, self::trip(['is_business' => false, 'purpose' => null, 'to' => 'Portrush'])),
        );
        self::assertFalse($private->get('entry', 'is_business'));
        self::assertNull($private->get('entry', 'purpose'));
    }

    public function testASavedJourneyFillsTheTripAndAReturnDoublesItsOneWayDistance(): void
    {
        $journey = $this->journey($this->owner, new SavedJourneyData(
            fromPlace: 'Ballymena',
            toPlace: 'Belfast',
            distanceKm: '45.250',
            isReturnDefault: true,
            purposeDefault: 'Site visit',
        ));

        $response = $this->api->post($this->trips, ['journey_id' => $journey->id, 'travelled_on' => '2026-09-28']);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame(
            ['Ballymena', 'Belfast', true, '90.500', true, 'Site visit'],
            [
                $entry->get('from'),
                $entry->get('to'),
                $entry->get('is_return'),
                $entry->get('distance_km'),
                $entry->get('is_business'),
                $entry->get('purpose'),
            ],
        );

        // The body's fields win: one way is the journey's distance, not doubled.
        $oneWay = ApiClient::json($this->api->post($this->trips, [
            'journey_id' => (string) $journey->id,
            'travelled_on' => '2026-09-29',
            'is_return' => false,
            'purpose' => 'Meeting',
        ]))->doc('entry');
        self::assertSame(
            ['45.250', false, 'Meeting'],
            [$oneWay->get('distance_km'), $oneWay->get('is_return'), $oneWay->get('purpose')],
        );
        // A measured distance wins over the journey's.
        $measured = ApiClient::json($this->api->post($this->trips, [
            'journey_id' => $journey->id,
            'travelled_on' => '2026-09-27',
            'distance_km' => '93',
        ]))->doc('entry');
        self::assertSame('93.000', $measured->get('distance_km'));

        // Another user's journey, or none, is not a choice.
        $member = $this->createMember($this->app);
        $theirs = $this->journey($member, new SavedJourneyData('Larne', 'Antrim', '30'));
        foreach ([$theirs->id, 999999, 'x'] as $id) {
            $refused = $this->api->post($this->trips, ['journey_id' => $id]);
            self::assertSame(422, $refused->getStatusCode(), (string) $id);
            self::assertSame('validation.choice', ApiClient::json($refused)->get('errors', 'journey_id', 'key'));
        }
        self::assertSame(3, $this->storedTrips());
    }

    public function testInvalidTripsAnswerWithTheFormsMessagesUnderTheApisNames(): void
    {
        $empty = $this->api->post($this->trips, []);
        self::assertSame(422, $empty->getStatusCode());
        $errors = ApiClient::json($empty)->doc('errors');
        self::assertSame(['from', 'to', 'purpose', 'distance_km'], $errors->keys());
        self::assertSame('trip.error.distance', $errors->get('distance_km', 'key'));
        self::assertSame('trip.error.purpose', $errors->get('purpose', 'key'));
        self::assertNotSame('', $errors->get('from', 'message'));

        $cases = [
            'odometers disagree' => [
                ['odometer_start_km' => '100', 'odometer_end_km' => '150', 'distance_km' => '60'],
                'distance_km',
                'trip.error.odometer_mismatch',
            ],
            'one odometer' => [['odometer_start_km' => '100'], 'odometer_end_km', 'trip.error.odometer_pair'],
            'in the future' => [['travelled_on' => '2026-10-01'], 'travelled_on', 'trip.error.future'],
            'not a date' => [['travelled_on' => '29/09/2026'], 'travelled_on', null],
            'a comma' => [['distance_km' => '45,2'], 'distance_km', 'validation.number'],
            'a flag as text' => [['is_return' => 'yes'], 'is_return', 'api.validation.boolean'],
            'too many passengers' => [['passengers' => 9], 'passengers', null],
            'misspelt' => [['distance' => '45'], 'distance', 'api.validation.unknown_field'],
        ];
        foreach ($cases as $case => [$body, $field, $key]) {
            $response = $this->api->post($this->trips, self::trip($body));
            self::assertSame(422, $response->getStatusCode(), $case . ': ' . self::body($response));
            $errors = ApiClient::json($response)->doc('errors');
            self::assertTrue($errors->has($field), $case . ': ' . self::body($response));
            if ($key !== null) {
                self::assertSame($key, $errors->get($field, 'key'), $case);
            }
        }
        self::assertSame(400, $this->api->post($this->trips, '[1]')->getStatusCode());
        self::assertSame(0, $this->storedTrips());
    }

    public function testATripLongerThanTheOdometerAllowsIsLoggedWithAWarning(): void
    {
        // 20 km between the evening before and the morning after (Europe/London).
        $this->reading($this->app, $this->golf, '10000', '2026-09-27T20:00:00Z');
        $this->reading($this->app, $this->golf, '10020', '2026-09-29T07:00:00Z');

        $response = $this->api->post($this->trips, self::trip(['travelled_on' => '2026-09-28', 'distance_km' => '50']));

        self::assertSame(201, $response->getStatusCode(), self::body($response));
        self::assertSame(['trip_longer_than_driven'], ApiClient::json($response)->column('code', 'warnings'));
        $within = $this->api->post($this->trips, self::trip(['travelled_on' => '2026-09-28', 'distance_km' => '15']));
        self::assertSame([], ApiClient::json($within)->get('warnings'));
    }

    public function testAnArchivedVehicleAndAReadKeyRefuseTrips(): void
    {
        $read = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read, 'Read only'));
        $refused = $read->post($this->trips, self::trip());
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('insufficient_scope', ApiClient::json($refused)->get('code'));
        self::assertSame(200, $read->get($this->trips)->getStatusCode(), 'a read key still lists');

        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);
        $archived = $this->api->post($this->trips, self::trip());
        self::assertSame(409, $archived->getStatusCode());
        self::assertSame('vehicle_archived', ApiClient::json($archived)->get('code'));
        self::assertSame(0, $this->storedTrips());
    }

    public function testADriverSeesTheirOwnTripsAndTheOwnerSeesEveryones(): void
    {
        $member = $this->createMember($this->app);
        $this->service($this->app, VehicleShareRepository::class)
            ->insert($this->golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $driver = $this->api($this->app, $this->apiKey($this->app, $member));

        $mine = ApiClient::json($this->api->post($this->trips, self::trip(['travelled_on' => '2026-09-20'])))->int('entry', 'id');
        $theirs = $driver->post($this->trips, self::trip(['travelled_on' => '2026-09-20']));
        // The same day, places and distance by another driver is their own trip, not a duplicate of the owner's.
        self::assertSame(201, $theirs->getStatusCode(), self::body($theirs));
        $theirId = ApiClient::json($theirs)->int('entry', 'id');
        self::assertSame($member->id, ApiClient::json($theirs)->get('entry', 'created_by'));
        $later = ApiClient::json($driver->post($this->trips, self::trip(['travelled_on' => '2026-09-25', 'to' => 'Lisburn'])))
            ->int('entry', 'id');

        self::assertSame([$later, $theirId], ApiClient::json($driver->get($this->trips))->column('id', 'items'));
        self::assertSame([$later, $theirId, $mine], ApiClient::json($this->api->get($this->trips))->column('id', 'items'));

        // Paged and filtered on the trip's date.
        $first = ApiClient::json($this->api->get($this->trips . '?limit=2'));
        self::assertSame([$later, $theirId], $first->column('id', 'items'));
        self::assertIsString($first->get('next'));
        self::assertSame([$later], ApiClient::json($this->api->get($this->trips . '?since=2026-09-21'))->column('id', 'items'));

        // Each claim is its driver's own.
        self::assertSame([$mine], ApiClient::json($this->api->get('/trips/claim'))->column('id', 'trips'));
        self::assertSame([$theirId, $later], ApiClient::json($driver->get('/trips/claim'))->column('id', 'trips'));
    }

    public function testTheClaimCarriesTheReportsFigures(): void
    {
        // 100 miles on 1 May 2026: HMRC's 55p a mile, provided on first use.
        $this->api->post(
            $this->trips,
            self::trip(['travelled_on' => '2026-05-01', 'distance_km' => '160.934', 'passengers' => 1]),
        );
        $this->api->post($this->trips, self::trip(['travelled_on' => '2026-06-01', 'is_business' => false, 'to' => 'Portrush']));
        // The tax year before.
        $this->api->post($this->trips, self::trip(['travelled_on' => '2026-03-01', 'distance_km' => '16.093']));

        $response = $this->api->get('/trips/claim');
        self::assertSame(200, $response->getStatusCode(), self::body($response));
        $claim = ApiClient::json($response);
        self::assertSame(['from' => '2026-04-06', 'to' => '2027-04-05', 'tax_year' => '2026/27'], $claim->get('period'));
        self::assertSame(0, $claim->get('unvalued'));
        self::assertCount(1, $claim->doc('trips'));
        $trip = $claim->doc('trips', 0);
        self::assertSame(
            ['2026-05-01', 'Ballymena → Belfast', 'Client visit', '160.934', '100.000', 'mi', 1, '5.000', '60.000', 'GBP'],
            [
                $trip->get('travelled_on'),
                $trip->get('journey'),
                $trip->get('purpose'),
                $trip->get('distance_km'),
                $trip->get('distance'),
                $trip->get('unit'),
                $trip->get('passengers'),
                $trip->get('passenger_amount'),
                $trip->get('amount'),
                $trip->get('currency'),
            ],
        );
        self::assertSame([['distance' => '100.000', 'rate' => '0.5500']], $trip->get('lines'));
        self::assertSame([[
            'currency' => 'GBP',
            'rates' => [['unit' => 'mi', 'rate' => '0.5500', 'distance' => '100.000', 'amount' => '55.000']],
            'mileage_amount' => '55.000',
            'passenger_amount' => '5.000',
            'approved_amount' => '60.000',
            'employer_amount' => null,
            'difference' => null,
        ]], $claim->get('totals'));

        $before = ApiClient::json($this->api->get('/trips/claim?year=2025'));
        self::assertSame('2025/26', $before->get('period', 'tax_year'));
        self::assertSame('4.500', $before->get('trips', 0, 'amount'), '10 miles at 45p');

        $custom = ApiClient::json($this->api->get('/trips/claim?period=custom&from=2026-01-01&to=2026-12-31'));
        self::assertSame(['from' => '2026-01-01', 'to' => '2026-12-31', 'tax_year' => null], $custom->get('period'));
        self::assertCount(2, $custom->doc('trips'));
        $other = $this->vehicle($this->app, 'Ford', 'Fiesta');
        self::assertSame([], ApiClient::json($this->api->get('/trips/claim?vehicles[]=' . $other->id))->get('trips'));
        self::assertCount(1, ApiClient::json($this->api->get('/trips/claim?vehicles[]=' . $this->golf->id))->doc('trips'));

        $queries = ['year=26', 'period=weekly', 'period=custom&from=2026-01-01', 'from=2026-02-30&to=2026-03-01',
            'from=2026-03-01&to=2026-02-01', 'vehicles[]=x', 'year=2026&from=2026-01-01&to=2026-02-01'];
        foreach ($queries as $query) {
            $bad = $this->api->get('/trips/claim?' . $query);
            self::assertSame(400, $bad->getStatusCode(), $query);
            self::assertSame('invalid_parameter', ApiClient::json($bad)->get('code'), $query);
        }
        self::assertSame(404, $this->api->get('/trips/claim?vehicles[]=999999')->getStatusCode());
    }

    public function testSavedJourneysAreListedInTheUsersOrder(): void
    {
        $belfast = $this->journey($this->owner, new SavedJourneyData('Ballymena', 'Belfast', '45.25', true, 'Site visit'));
        $larne = $this->journey($this->owner, new SavedJourneyData('Ballymena', 'Larne', '30', false, null, false));
        $this->service($this->app, SavedJourneyService::class)->move($this->owner, $larne, -1);
        $member = $this->createMember($this->app);
        $this->journey($member, new SavedJourneyData('Antrim', 'Lisburn', '20'));

        $list = ApiClient::json($this->api->get('/journeys'));

        self::assertSame([$larne->id, $belfast->id], $list->column('id', 'items'), 'their own, in their order');
        $first = $list->doc('items', 1);
        self::assertSame(
            ['Ballymena', 'Belfast', 'Ballymena → Belfast → Ballymena', '45.250', true, true, 'Site visit'],
            [
                $first->get('from'),
                $first->get('to'),
                $first->get('journey'),
                $first->get('distance_km'),
                $first->get('is_return'),
                $first->get('is_business'),
                $first->get('purpose'),
            ],
        );
        self::assertNull($list->get('items', 0, 'purpose'));
        self::assertFalse($list->get('items', 0, 'is_business'));

        $logged = $this->api->post($this->trips, ['journey_id' => $list->int('items', 1, 'id'), 'travelled_on' => '2026-09-28']);
        self::assertSame(201, $logged->getStatusCode(), 'an id from the list logs a trip');
    }

    public function testWithTripsOffEveryTripPathIsNotFound(): void
    {
        $app = $this->createApp();
        $api = $this->api($app, $this->apiKey($app, $this->owner));

        self::assertSame(404, $api->get($this->trips)->getStatusCode());
        self::assertSame(404, $api->post($this->trips, self::trip())->getStatusCode());
        self::assertSame(404, $api->get('/trips/claim')->getStatusCode());
        self::assertSame(404, $api->get('/journeys')->getStatusCode());
        self::assertSame(404, $api->post('/journeys', ['from' => 'A', 'to' => 'B', 'distance_km' => '1'])->getStatusCode());
        self::assertSame(404, $api->patch('/journeys/1', ['to' => 'C'])->getStatusCode());
        self::assertSame(404, $api->delete('/journeys/1')->getStatusCode());
        self::assertFalse(ApiClient::json($api->get('/me'))->get('modules', 'trips'));
    }

    public function testAJourneyIsSavedEditedAndDeletedAsTheSettingsFormDoes(): void
    {
        $created = $this->api->post('/journeys', ['from' => 'Ballymena', 'to' => 'Belfast', 'distance_km' => 45.25]);
        self::assertSame(201, $created->getStatusCode(), self::body($created));
        $journey = ApiClient::json($created)->doc('entry');
        self::assertSame('45.250', $journey->get('distance_km'), 'one way, in km, whatever the owner\'s unit');
        self::assertTrue($journey->get('is_business'), 'as the form starts');
        self::assertFalse($journey->get('is_return'));
        $id = $journey->int('id');
        self::assertSame([$id], ApiClient::json($this->api->get('/journeys'))->column('id', 'items'));

        $response = $this->api->patch('/journeys/' . $id, ['is_return' => true, 'purpose' => 'Site visit']);
        $edited = ApiClient::json($response);
        self::assertSame(412, $this->api->patch('/journeys/' . $id, ['to' => 'x'], ['If-Match' => '"stale"'])->getStatusCode());
        self::assertSame(412, $this->api->delete('/journeys/' . $id, ['If-Match' => '"stale"'])->getStatusCode());
        $tag = $response->getHeaderLine('ETag');
        self::assertSame(200, $this->api->patch('/journeys/' . $id, ['to' => 'Belfast'], ['If-Match' => $tag])->getStatusCode());
        self::assertSame('Ballymena → Belfast → Ballymena', $edited->get('entry', 'journey'));
        self::assertSame('45.250', $edited->get('entry', 'distance_km'), 'unsent fields stay');
        self::assertNull(ApiClient::json($this->api->patch('/journeys/' . $id, ['purpose' => null]))->get('entry', 'purpose'));

        $unknown = $this->api->post('/journeys', ['from' => 'Ballymena', 'colour' => 'red']);
        self::assertSame(['colour'], array_keys(ApiClient::json($unknown)->doc('errors')->toArray()));
        $missing = $this->api->post('/journeys', ['from' => 'Ballymena']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(['to', 'distance_km'], array_keys(ApiClient::json($missing)->doc('errors')->toArray()));
        self::assertSame(422, $this->api->patch('/journeys/' . $id, ['to' => null])->getStatusCode());

        $trip = $this->api->post($this->trips, ['journey_id' => $id, 'travelled_on' => '2026-09-28', 'purpose' => 'Visit']);
        self::assertSame(201, $trip->getStatusCode());
        $member = $this->createMember($this->app);
        $theirs = $this->api($this->app, $this->apiKey($this->app, $member));
        self::assertSame(404, $theirs->patch('/journeys/' . $id, ['to' => 'Larne'])->getStatusCode(), 'their own only');
        self::assertSame(404, $theirs->delete('/journeys/' . $id)->getStatusCode());
        $reader = $this->api($this->app, $this->apiKey($this->app, $this->owner, ApiScope::Read));
        self::assertSame('insufficient_scope', ApiClient::json($reader->delete('/journeys/' . $id))->get('code'));

        self::assertSame(204, $this->api->delete('/journeys/' . $id)->getStatusCode());
        self::assertSame([], ApiClient::json($this->api->get('/journeys'))->get('items'));
        self::assertSame(1, count((array) ApiClient::json($this->api->get($this->trips))->get('items')), 'trips stay');
        self::assertSame(404, $this->api->delete('/journeys/' . $id)->getStatusCode());
    }
}
