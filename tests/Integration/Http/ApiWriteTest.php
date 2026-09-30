<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The two writes (spec.md §7.20): through the forms' parsers and services,
 * in the request's units, with the form's messages, warnings that never
 * block, safe retries, and no writes to an archived vehicle.
 */
final class ApiWriteTest extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    /** @var App<ContainerInterface> */
    private App $app;
    private User $owner;
    private Vehicle $golf;
    private ApiClient $api;
    private string $fuel;
    private string $odometer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, '2026-09-29T10:00:00Z');
        $this->resetDatabase($this->app);
        // UK preferences: miles and litres.
        $this->owner = $this->createOwner($this->app);
        $this->golf = $this->vehicle($this->app);
        $this->api = $this->api($this->app, $this->apiKey($this->app, $this->owner));
        $this->fuel = '/vehicles/' . $this->golf->id . '/fuel';
        $this->odometer = '/vehicles/' . $this->golf->id . '/odometer';
    }

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * What the fill-up form stores for the same values typed in these units.
     *
     * @param array<string, string> $input
     */
    private function asTheFormStores(array $input, DistanceUnit $distance, VolumeUnit $volume): FuelEntryData
    {
        $preferences = new DisplayPreferences('en_GB', 'UTC', $distance, $volume, ConsumptionUnit::MpgUk, 'GBP');
        $data = FuelEntryForm::parse($input + ['fuel' => 'petrol'], $preferences, 'GBP');
        self::assertInstanceOf(FuelEntryData::class, $data);

        return $data;
    }

    private function stored(int $id): FuelEntryData
    {
        return $this->service($this->app, FuelService::class)->get($this->golf, $id)->data;
    }

    public function testAnyTwoAmountsDeriveTheThirdAsTheFormDoes(): void
    {
        $cases = [
            'volume and total' => [
                ['volume' => '44.21', 'total_cost' => '61.37'],
                ['volume' => '44.21', 'total' => '61.37'],
            ],
            'volume and price' => [
                ['volume' => '44.21', 'price_per_unit' => '1.389'],
                ['volume' => '44.21', 'price' => '1.389'],
            ],
            'price and total' => [
                ['price_per_unit' => '1.389', 'total_cost' => '61.37'],
                ['price' => '1.389', 'total' => '61.37'],
            ],
        ];
        $odometer = 30000;
        $minute = 10;
        foreach ($cases as $case => [$body, $form]) {
            $odometer += 300;
            $minute += 1;
            $at = sprintf('2026-09-01T08:%02d:00Z', $minute);
            $response = $this->api->post($this->fuel, $body + ['odometer' => (string) $odometer, 'filled_at' => $at]);
            self::assertSame(201, $response->getStatusCode(), $case . ': ' . self::body($response));
            $entry = ApiClient::json($response)->doc('entry');

            $expected = $this->asTheFormStores(
                $form + ['odometer' => (string) $odometer, 'filled_at' => substr($at, 0, 16)],
                DistanceUnit::Mile,
                VolumeUnit::Litre,
            );
            $stored = $this->stored($entry->int('id'));
            self::assertSame(
                [$expected->volume, $expected->pricePerUnit, $expected->totalCost, $expected->odometerKm],
                [$stored->volume, $stored->pricePerUnit, $stored->totalCost, $stored->odometerKm],
                $case,
            );
            self::assertSame(
                [$stored->volume, $stored->pricePerUnit, $stored->totalCost],
                [$entry->get('volume'), $entry->get('price_per_unit'), $entry->get('total_cost')],
                $case,
            );
        }
    }

    public function testGallonsAndMilesConvertExactly(): void
    {
        $response = $this->api->post($this->fuel, [
            'filled_at' => '2026-09-28T07:42:00Z',
            'odometer' => '30280',
            'distance_unit' => 'mi',
            'volume' => '8.5',
            'volume_unit' => 'gal_uk',
            'total_cost' => '61.20',
        ]);
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');

        // 30,280 mi × 1.609344 = 48,730.93632 km; 8.5 UK gal × 4.54609 = 38.641765 l.
        self::assertSame('48730.936', $entry->get('odometer'));
        self::assertSame('38.642', $entry->get('volume'));
        self::assertSame('61.200', $entry->get('total_cost'));
        $expected = $this->asTheFormStores(
            ['filled_at' => '2026-09-28T07:42', 'odometer' => '30280', 'volume' => '8.5', 'total' => '61.20'],
            DistanceUnit::Mile,
            VolumeUnit::UkGallon,
        );
        self::assertSame($expected->pricePerUnit, $entry->get('price_per_unit'));

        $us = ApiClient::json($this->api->post($this->fuel, [
            'filled_at' => '2026-09-29T07:42:00Z',
            'odometer' => '48900',
            'distance_unit' => 'km',
            'volume' => 10,
            'volume_unit' => 'gal_us',
            'price_per_unit' => 3.459,
        ]))->doc('entry');
        self::assertSame('48900.000', $us->get('odometer'));
        self::assertSame('37.854', $us->get('volume'), '10 US gal × 3.785411784');
        self::assertSame('34.590', $us->get('total_cost'));
        self::assertSame('2026-09-29T07:42:00Z', $us->get('filled_at'));
    }

    public function testJsonNumbersAreReadAsDecimalsNotFloats(): void
    {
        // As a float, 40123.4565 is 40123.45649999…, which rounds down; as a decimal it rounds up.
        $response = $this->api->post(
            $this->fuel,
            '{"filled_at": "2026-09-28T07:42:00Z", "odometer": 40123.4565, "distance_unit": "km", '
            . '"volume": 40.0005, "total_cost": 60.0000000000000001}',
        );
        self::assertSame(201, $response->getStatusCode(), self::body($response));
        $entry = ApiClient::json($response)->doc('entry');

        self::assertSame('40123.457', $entry->get('odometer'), 'rounded half up at the stored scale');
        self::assertSame('40.001', $entry->get('volume'));
        self::assertSame('60.000', $entry->get('total_cost'));
    }

    public function testDefaultsAreTheFormsAndInstantsAreKeptToTheMinute(): void
    {
        $response = $this->api->post($this->fuel, [
            'odometer' => '30000',
            'volume' => '40',
            'total_cost' => '60',
            'filled_at' => '2026-09-28T08:42:37+01:00',
        ]);
        $entry = ApiClient::json($response)->doc('entry');
        self::assertSame('2026-09-28T07:42:00Z', $entry->get('filled_at'));
        self::assertSame('petrol', $entry->get('fuel'));
        self::assertSame('48280.320', $entry->get('odometer'), 'miles, the owner\'s unit');

        $now = ApiClient::json($this->api->post($this->fuel, ['odometer' => '30500', 'volume' => '40', 'total_cost' => '60']))
            ->doc('entry');
        self::assertSame('2026-09-29T10:00:00Z', $now->get('filled_at'));

        $graded = ApiClient::json($this->api->post($this->fuel, [
            'filled_at' => '2026-09-29T11:00:00Z',
            'odometer' => '31000',
            'volume' => '40',
            'total_cost' => '60',
            'grade' => 'e5_97',
        ]))->doc('entry');
        self::assertSame(['petrol', 'e5_97'], [$graded->get('fuel'), $graded->get('grade')]);
    }

    public function testARetryReturnsTheExistingEntryAndWritesNothing(): void
    {
        $body = ['filled_at' => '2026-09-28T07:42:00Z', 'odometer' => '30280', 'volume' => '40', 'total_cost' => '60'];
        $first = $this->api->post($this->fuel, $body);
        self::assertSame(201, $first->getStatusCode());

        // The same fill-up again, as a JSON number, with a different station: still the same one.
        $retry = $this->api->post($this->fuel, ['odometer' => 30280, 'station' => 'Shell'] + $body);
        self::assertSame(200, $retry->getStatusCode());
        $again = ApiClient::json($retry);
        self::assertTrue($again->get('duplicate'));
        self::assertSame(ApiClient::json($first)->get('entry', 'id'), $again->get('entry', 'id'));
        self::assertNull($again->get('entry', 'station'));
        self::assertEquals(1, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM fuel_entries'));
        self::assertEquals(1, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));

        // An odometer reading that repeats the fill-up's own reading is a duplicate too.
        $reading = $this->api->post($this->odometer, ['recorded_at' => '2026-09-28T07:42:00Z', 'odometer' => '30280']);
        self::assertSame(200, $reading->getStatusCode());
        self::assertSame('fuel', ApiClient::json($reading)->get('entry', 'source'));
        self::assertEquals(1, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));

        $manual = $this->api->post(
            $this->odometer,
            ['recorded_at' => '2026-09-29T07:00:00Z', 'odometer' => '30300', 'note' => 'OBD'],
        );
        self::assertSame(201, $manual->getStatusCode());
        self::assertSame(['manual', 'OBD', '48763.123'], [
            ApiClient::json($manual)->get('entry', 'source'),
            ApiClient::json($manual)->get('entry', 'note'),
            ApiClient::json($manual)->get('entry', 'odometer'),
        ]);
        $repeat = $this->api->post($this->odometer, ['recorded_at' => '2026-09-29T07:00:00Z', 'odometer' => '30300']);
        self::assertSame(200, $repeat->getStatusCode());
        self::assertEquals(2, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
    }

    public function testValidationErrorsCarryTheFormsMessageKeys(): void
    {
        $cases = [
            'no odometer' => [
                ['volume' => '40', 'total_cost' => '60'],
                'odometer',
                'validation.required',
                ['volume' => '40', 'total' => '60'],
            ],
            'one amount' => [
                ['odometer' => '30000', 'volume' => '40'],
                'volume',
                'fuel.need_two',
                ['odometer' => '30000', 'volume' => '40'],
            ],
            'zero volume' => [
                ['odometer' => '30000', 'volume' => '0', 'total_cost' => '5'],
                'volume',
                'validation.positive',
                ['odometer' => '30000', 'volume' => '0', 'total' => '5'],
            ],
            'negative total' => [
                ['odometer' => '30000', 'volume' => '40', 'total_cost' => '-1'],
                'total_cost',
                'validation.min',
                ['odometer' => '30000', 'volume' => '40', 'total' => '-1'],
            ],
            'unknown fuel' => [
                ['odometer' => '30000', 'volume' => '40', 'total_cost' => '60', 'fuel' => 'coal'],
                'fuel',
                'validation.choice',
                ['odometer' => '30000', 'volume' => '40', 'total' => '60', 'fuel' => 'coal'],
            ],
            'grade of another fuel' => [
                ['odometer' => '30000', 'volume' => '40', 'total_cost' => '60', 'fuel' => 'diesel', 'grade' => 'e10_95'],
                'fuel',
                'fuel.grade_mismatch',
                ['odometer' => '30000', 'volume' => '40', 'total' => '60', 'fuel' => 'diesel', 'grade' => 'e10_95'],
            ],
            'long station' => [
                ['odometer' => '30000', 'volume' => '40', 'total_cost' => '60', 'station' => str_repeat('x', 101)],
                'station',
                'validation.too_long',
                ['odometer' => '30000', 'volume' => '40', 'total' => '60', 'station' => str_repeat('x', 101)],
            ],
        ];
        $preferences = new DisplayPreferences('en', 'UTC', DistanceUnit::Mile, VolumeUnit::Litre, ConsumptionUnit::MpgUk, 'GBP');
        foreach ($cases as $case => [$body, $field, $key, $form]) {
            $response = $this->api->post($this->fuel, $body);
            self::assertSame(422, $response->getStatusCode(), $case);
            $problem = ApiClient::json($response);
            self::assertSame('validation_failed', $problem->get('code'), $case);
            self::assertSame($key, $problem->get('errors', $field, 'key'), $case . ': ' . self::body($response));
            self::assertNotSame('', $problem->get('errors', $field, 'message'), $case);

            $formErrors = FuelEntryForm::parse(
                $form + ['filled_at' => '2026-09-28T07:42', 'fuel' => $form['fuel'] ?? 'petrol'],
                $preferences,
                'GBP',
            );
            self::assertInstanceOf(ValidationErrors::class, $formErrors, $case);
            self::assertContains($key, array_column($formErrors->all(), 'key'), $case . ': the form says the same');
        }
        $oneAmount = $this->api->post($this->fuel, ['odometer' => '1', 'volume' => '4']);
        self::assertSame(
            'Enter at least two of volume, price and total.',
            ApiClient::json($oneAmount)->get('errors', 'volume', 'message'),
        );
        self::assertEquals(0, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM fuel_entries'));
    }

    public function testTheApisOwnInputRulesAnswerWithTheirOwnKeys(): void
    {
        $cases = [
            'a misspelt field' => [
                ['odometer' => '1', 'volume' => '4', 'price' => '1.5'],
                'price',
                'api.validation.unknown_field',
            ],
            'a comma decimal' => [
                ['odometer' => '1', 'volume' => '4,5', 'total_cost' => '6'],
                'volume',
                'validation.number',
            ],
            'a time without zone' => [
                ['odometer' => '1', 'volume' => '4', 'total_cost' => '6', 'filled_at' => '2026-09-28T07:42'],
                'filled_at',
                'api.validation.instant',
            ],
            'a flag as text' => [
                ['odometer' => '1', 'volume' => '4', 'total_cost' => '6', 'is_partial' => 'yes'],
                'is_partial',
                'api.validation.boolean',
            ],
            'kWh for petrol' => [
                ['odometer' => '1', 'volume' => '4', 'total_cost' => '6', 'volume_unit' => 'kwh'],
                'volume_unit',
                'api.validation.kwh_for_electric',
            ],
            'an unknown unit' => [
                ['odometer' => '1', 'volume' => '4', 'total_cost' => '6', 'distance_unit' => 'furlong'],
                'distance_unit',
                'validation.choice',
            ],
        ];
        foreach ($cases as $case => [$body, $field, $key]) {
            $response = $this->api->post($this->fuel, $body);
            self::assertSame(422, $response->getStatusCode(), $case . ': ' . self::body($response));
            self::assertSame(
                $key,
                ApiClient::json($response)->get('errors', $field, 'key'),
                $case . ': ' . self::body($response),
            );
        }

        $exponent = $this->api->post($this->fuel, '{"odometer": 1e5, "volume": "4", "total_cost": "6"}');
        self::assertSame('validation.number', ApiClient::json($exponent)->get('errors', 'odometer', 'key'), 'an exponent');

        foreach (['[1, 2]', 'not json', '"a string"', '{"odometer": }'] as $body) {
            $response = $this->api->post($this->fuel, $body);
            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertSame('invalid_body', ApiClient::json($response)->get('code'));
        }
        $empty = $this->api->post($this->odometer, '');
        self::assertSame('validation.required', ApiClient::json($empty)->get('errors', 'odometer', 'key'));
        self::assertEquals(0, $this->connection($this->app)->fetchOne('SELECT COUNT(*) FROM odometer_readings'));
    }

    public function testElectricityIsLoggedInKwh(): void
    {
        $ev = $this->vehicle($this->app, 'Kia', 'Niro EV', null, FuelType::Electric);
        $path = '/vehicles/' . $ev->id . '/fuel';

        $entry = ApiClient::json($this->api->post($path, [
            'filled_at' => '2026-09-28T07:42:00Z',
            'odometer' => '10000',
            'volume' => '52.4',
            'volume_unit' => 'kwh',
            'price_per_unit' => '0.245',
            'grade' => 'dc_rapid',
        ]))->doc('entry');
        // 52.4 × 0.245 = 12.838, rounded to the currency's pence as the form does.
        self::assertSame(['ev', 'dc_rapid', '52.400', 'kwh', '12.840'], [
            $entry->get('fuel'),
            $entry->get('grade'),
            $entry->get('volume'),
            $entry->get('volume_unit'),
            $entry->get('total_cost'),
        ]);

        $response = $this->api->post($path, ['odometer' => '10200', 'volume' => '40', 'volume_unit' => 'l', 'total_cost' => '9']);
        self::assertSame('api.validation.electric_in_kwh', ApiClient::json($response)->get('errors', 'volume_unit', 'key'));
    }

    public function testWarningsAreReturnedAndNeverBlock(): void
    {
        $this->reading($this->app, $this->golf, '50000', '2026-09-01T08:00:00Z');

        $backwards = $this->api->post(
            $this->odometer,
            ['recorded_at' => '2026-09-20T08:00:00Z', 'odometer' => '40000', 'distance_unit' => 'km'],
        );
        self::assertSame(201, $backwards->getStatusCode());
        self::assertSame(['odometer_backwards'], ApiClient::json($backwards)->column('code', 'warnings'));

        $jump = $this->api->post($this->fuel, [
            'filled_at' => '2026-09-21T08:00:00Z',
            'odometer' => '90000',
            'distance_unit' => 'km',
            'volume' => '40',
            'total_cost' => '60',
        ]);
        self::assertSame(201, $jump->getStatusCode());
        self::assertSame(['odometer_jump'], ApiClient::json($jump)->column('code', 'warnings'));
    }

    public function testAnEconomyCheckFlagIsAWarning(): void
    {
        // Six tanks of 6 l/100 km make the baseline.
        for ($i = 0; $i < 6; ++$i) {
            $at = sprintf('2026-08-%02dT08:00:00Z', 1 + $i * 3);
            $this->fillUp($this->app, $this->golf, $at, (string) (10000 + $i * 500), '30', '45');
        }

        $response = $this->api->post($this->fuel, [
            'filled_at' => '2026-09-01T08:00:00Z',
            'odometer' => '13000',
            'distance_unit' => 'km',
            'volume' => '60',
            'total_cost' => '90',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $result = ApiClient::json($response);
        self::assertSame(['economy_check'], $result->column('code', 'warnings'));
        self::assertSame(['verdict' => 'more', 'flagged' => true, 'confirmed' => false], $result->get('entry', 'economy_check'));
    }

    public function testAnArchivedVehicleRefusesWrites(): void
    {
        $this->service($this->app, VehicleService::class)->archive($this->owner, $this->golf);

        $writes = [
            $this->fuel => ['odometer' => '1', 'volume' => '4', 'total_cost' => '6'],
            $this->odometer => ['odometer' => '1'],
        ];
        foreach ($writes as $path => $body) {
            $response = $this->api->post($path, $body);
            self::assertSame(409, $response->getStatusCode(), $path);
            self::assertSame('vehicle_archived', ApiClient::json($response)->get('code'));
        }
        self::assertSame(200, $this->api->get($this->fuel)->getStatusCode(), 'still readable');
        self::assertSame([], $this->service($this->app, OdometerService::class)->history($this->golf)->readings);
    }
}
