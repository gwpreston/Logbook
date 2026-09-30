<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Fuel grades end to end (spec.md §7.3, Phase 8): the grouped picker, the
 * grade saved with a fill-up (page and modal), badges, the By grade card,
 * the vehicle's default grade, and a stored code this release does not know.
 * The owner uses UK units (miles, litres, GBP, en_GB).
 */
final class FuelGradeTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const array MODAL = ['X-Logbook-Modal' => '1'];
    private const array FILL = [
        'filled_at' => '2026-07-01T09:15',
        'odometer' => '10000',
        'fuel' => 'petrol:e10_95',
        'volume' => '40',
        'price' => '1.5',
        'total' => '',
        'station' => '',
        'notes' => '',
    ];

    public function testAFillUpIsSavedWithAnyGradeOfItsFamilyOrNone(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/fuel';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('<optgroup label="Petrol">', $form);
        self::assertStringContainsString('<option value="petrol" selected>Petrol — grade not recorded</option>', $form);
        self::assertStringContainsString('<option value="petrol:e10_95">E10 unleaded, 95 RON</option>', $form);
        self::assertStringContainsString('<optgroup label="Other fuels">', $form);
        self::assertStringContainsString('<option value="diesel:b7">B7 diesel</option>', $form);
        self::assertStringContainsString('<optgroup label="More grades">', $form);
        // A UK owner finds the US pump grades under More grades.
        self::assertStringContainsString('<option value="petrol:aki_87">Regular, 87 AKI</option>', $form);
        self::assertStringNotContainsString('Used on this vehicle', $form, 'nothing used yet');

        $browser->post($base . '/new', self::FILL);
        $plainFill = ['filled_at' => '2026-07-08T09:00', 'odometer' => '10400', 'fuel' => 'petrol'];
        $browser->post($base . '/new', $plainFill + self::FILL);
        [$graded, $plain] = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertSame(FuelGrade::E10_95, $graded->data->grade);
        self::assertNull($plain->data->grade, 'not recorded');

        // A grade from another family is refused with a clear message.
        $refused = $browser->post($base . '/new', ['odometer' => '10800', 'fuel' => 'petrol:b7'] + self::FILL);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('B7 is a diesel grade; this fill-up is petrol.', self::body($refused));
        self::assertCount(2, $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));

        // The next fill-up preselects the last grade, first in "Used on this vehicle".
        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('<optgroup label="Used on this vehicle">', $form);
        self::assertSame(1, substr_count($form, ' selected>'), 'selected once, although listed twice');
        self::assertStringContainsString('<option value="petrol:e10_95" selected>', $form);

        // Editing keeps the stored grade; the modal changes it.
        $edit = $base . '/' . $graded->id . '/edit';
        self::assertStringContainsString('<option value="petrol:e10_95" selected>', self::body($browser->get($edit)));
        $modal = self::body($browser->get($edit, self::MODAL));
        self::assertStringContainsString('<option value="petrol:e10_95" selected>', $modal);
        $saved = $browser->post($edit, ['fuel' => 'petrol:e5_97'] + self::FILL, [], true, self::MODAL);
        self::assertSame(204, $saved->getStatusCode());
        $graded = $this->service($app, FuelEntryRepository::class)->find($golf->id, $graded->id);
        self::assertSame(FuelGrade::E5_97, $graded?->data->grade);

        // Badges: shape and short label; no badge when not recorded.
        $list = self::body($browser->get($base));
        self::assertStringContainsString('grade-badge grade-badge--circle" title="E5 super unleaded, 97 RON"', $list);
        self::assertStringContainsString('<span class="grade-badge__text" aria-hidden="true">E5 97</span>', $list);
        // Its row and the By grade card; the fill with no grade has none.
        self::assertSame(2, substr_count($list, 'class="grade-badge '));
        self::assertStringContainsString('By grade', $list);
        self::assertStringContainsString('Not recorded', $list);

        // And on the dashboard's recent fuel and activity, and the Expenses ledger.
        self::assertSame(2, substr_count(self::body($browser->get('/')), 'title="E5 super unleaded, 97 RON"'));
        foreach (['/vehicles/' . $golf->id . '/expenses', '/vehicles/' . $golf->id] as $page) {
            self::assertStringContainsString('title="E5 super unleaded, 97 RON"', self::body($browser->get($page)), $page);
        }
    }

    public function testChargingTypesShowCostPerKwhAndShareOfEnergy(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $ev = $this->vehicle($app, 'Kia', 'EV6', fuel: FuelType::Electric);
        $this->fillUp($app, $ev, '2026-06-01T20:00:00Z', '1000', '60', '4.50', grade: FuelGrade::Home);
        $this->fillUp($app, $ev, '2026-06-10T12:00:00Z', '1300', '20', '15.80', grade: FuelGrade::DcRapid);
        $this->fillUp($app, $ev, '2026-06-20T20:00:00Z', '1600', '20', '0', grade: FuelGrade::Ac);

        $html = self::body($browser->get('/vehicles/' . $ev->id . '/fuel'));
        self::assertStringContainsString('By charging type', $html);
        self::assertStringContainsString('grade-badge--hexagon', $html);
        self::assertStringContainsString('60%', $html, 'home: 60 of 100 kWh');
        self::assertStringContainsString('£0.075/kWh', $html, '4.50 / 60 = 7.5p');
        self::assertStringContainsString('£0.79/kWh', $html);
        self::assertStringContainsString('£0.00/kWh', $html, 'a free charge counts at 0');
        self::assertStringContainsString('All charging', $html);
        self::assertStringContainsString('£0.203/kWh', $html, 'blended: 20.30 over 100 kWh');

        $form = self::body($browser->get('/vehicles/' . $ev->id . '/fuel/new'));
        self::assertStringContainsString('<optgroup label="Electricity">', $form);
        self::assertStringContainsString('<option value="ev:dc_ultra">Ultra-rapid DC charging (100 kW+)</option>', $form);
    }

    public function testAStoredCodeThisReleaseDoesNotKnowReadsAsNotRecorded(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $entry = $this->fillUp($app, $golf, '2026-06-01T09:00:00Z', '1000', '40', '60', grade: FuelGrade::E10_95);
        $connection = $this->connection($app);
        $connection->update('fuel_entries', ['grade' => 'e11_93'], ['id' => $entry->id]);
        $connection->update('vehicles', ['default_grade' => 'b7'], ['id' => $golf->id]);

        self::assertNull($this->service($app, FuelEntryRepository::class)->find($golf->id, $entry->id)?->data->grade);
        self::assertNull($this->service($app, VehicleRepository::class)->findById($golf->id)?->data->defaultGrade);

        $page = $browser->get('/vehicles/' . $golf->id . '/fuel');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringNotContainsString('grade-badge', self::body($page));
        self::assertSame(200, $browser->get('/vehicles/' . $golf->id . '/fuel/' . $entry->id . '/edit')->getStatusCode());
    }

    public function testTheVehicleDefaultGradeIsSavedAndClearedWhenTheFuelTypeChanges(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $form = self::body($browser->get('/vehicles/new'));
        self::assertStringContainsString('name="default_grade"', $form);
        self::assertStringContainsString('data-family="ev"', $form);

        $car = ['type' => 'car', 'make' => 'Toyota', 'model' => 'Prius', 'fuel_type' => 'hybrid', 'default_grade' => 'e5_97'];
        $browser->post('/vehicles/new', $car);
        $vehicle = $this->ownedVehicles($app, $this->owner($app)->id, false)[0];
        self::assertSame(FuelGrade::E5_97, $vehicle->data->defaultGrade, 'a hybrid takes a petrol grade');
        self::assertStringContainsString('<option value="petrol:e5_97" selected>', self::body(
            $browser->get('/vehicles/' . $vehicle->id . '/fuel/new'),
        ), 'preselected on the first fill-up');

        // Switched to diesel without JS: the petrol default is dropped, not refused.
        $response = $browser->post('/vehicles/' . $vehicle->id . '/edit', ['fuel_type' => 'diesel'] + $car);
        self::assertSame(303, $response->getStatusCode());
        $vehicle = $this->service($app, VehicleRepository::class)->findById($vehicle->id);
        self::assertSame(FuelType::Diesel, $vehicle?->data->fuelType);
        self::assertNull($vehicle->data->defaultGrade);

        $browser->post('/vehicles/' . $vehicle->id . '/edit', ['fuel_type' => 'ev', 'default_grade' => 'home'] + $car);
        $vehicle = $this->service($app, VehicleRepository::class)->findById($vehicle->id);
        self::assertSame(FuelGrade::Home, $vehicle?->data->defaultGrade);
        $edit = self::body($browser->get('/vehicles/' . $vehicle->id . '/edit'));
        self::assertStringContainsString('value="home" selected', $edit);

        $mains = ['fuel_type' => 'ev', 'default_grade' => 'mains'];
        $unknown = $browser->post('/vehicles/' . $vehicle->id . '/edit', $mains + $car);
        self::assertSame(422, $unknown->getStatusCode());
    }

    public function testNothingAboutGradesWithTheFuelModuleOff(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-01T09:00:00Z', '1000', '40', '60', grade: FuelGrade::E10_95);
        $this->service($app, FeatureToggles::class)
            ->save([Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports]);

        foreach (['/', '/vehicles/' . $golf->id, '/vehicles/' . $golf->id . '/expenses', '/reports'] as $page) {
            self::assertStringNotContainsString('grade-badge', self::body($browser->get($page)), $page);
        }
        self::assertSame(404, $browser->get('/vehicles/' . $golf->id . '/fuel')->getStatusCode());
    }
}
