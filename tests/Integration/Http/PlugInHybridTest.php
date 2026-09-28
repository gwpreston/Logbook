<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Self-charging and plug-in hybrids (Phase 9.2, spec.md §6, §7.1, §7.3):
 * the vehicle form (page and modal), the fill-up picker for each, a fill-up
 * and a charge on both, and the labels wherever a vehicle is described.
 */
final class PlugInHybridTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const array MODAL = ['X-Logbook-Modal' => '1'];
    private const array CAR = ['type' => 'car', 'make' => 'Toyota', 'model' => 'Prius', 'currency' => ''];
    private const array FILL = [
        'filled_at' => '2026-07-01T09:15',
        'odometer' => '10000',
        'volume' => '30',
        'price' => '1.5',
        'total' => '',
        'station' => '',
        'notes' => '',
    ];

    public function testTheVehicleFormOffersBothKindsWithTheirHints(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        foreach ([[], self::MODAL] as $headers) {
            $form = self::body($browser->get('/vehicles/new', $headers));
            self::assertMatchesRegularExpression(
                '~<option value="hybrid">Hybrid</option>\s*<option value="phev">Plug-in hybrid</option>~',
                $form,
                'side by side',
            );
            self::assertStringContainsString('aria-describedby="f-fuel_type-hint"', $form);
            self::assertStringContainsString('self-charging or mild hybrid; fills with petrol only.', $form);
            self::assertStringContainsString('fills with petrol and charges from a plug.', $form);
            self::assertStringContainsString('&quot;phev&quot;:&quot;petrol&quot;', $form, 'its grades are petrol grades');
            self::assertStringContainsString('Tank capacity', $form);
        }
    }

    public function testBothKindsAreAddedAndEditedOnThePageAndInTheModal(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        $browser->get('/vehicles/new');
        $page = $browser->post('/vehicles/new', ['fuel_type' => 'hybrid', 'default_grade' => 'e10_95'] + self::CAR);
        self::assertSame(303, $page->getStatusCode());
        $browser->get('/vehicles/new', self::MODAL);
        $outlander = ['fuel_type' => 'phev', 'model' => 'Outlander'] + self::CAR;
        $modal = $browser->post('/vehicles/new', $outlander, [], true, self::MODAL);
        self::assertSame(204, $modal->getStatusCode());

        [$hybrid, $phev] = $this->vehicles($app);
        self::assertSame(FuelType::Hybrid, $hybrid->data->fuelType);
        self::assertSame(FuelGrade::E10_95, $hybrid->data->defaultGrade);
        self::assertSame(FuelType::Phev, $phev->data->fuelType);

        // Hybrid → plug-in hybrid on the page keeps the petrol default; back
        // again in the modal, a charging default is dropped, not refused.
        $edit = '/vehicles/' . $hybrid->id . '/edit';
        $browser->get($edit);
        $browser->post($edit, ['fuel_type' => 'phev', 'default_grade' => 'e10_95'] + self::CAR);
        $edited = $this->find($app, $hybrid);
        self::assertSame(FuelType::Phev, $edited->data->fuelType);
        self::assertSame(FuelGrade::E10_95, $edited->data->defaultGrade);

        $browser->get($edit, self::MODAL);
        $saved = $browser->post($edit, ['fuel_type' => 'hybrid', 'default_grade' => 'home'] + self::CAR, [], true, self::MODAL);
        self::assertSame(204, $saved->getStatusCode());
        $edited = $this->find($app, $hybrid);
        self::assertSame(FuelType::Hybrid, $edited->data->fuelType);
        self::assertNull($edited->data->defaultGrade);

        $shown = self::body($browser->get('/vehicles/' . $phev->id));
        self::assertStringContainsString('Plug-in hybrid', $shown);
        self::assertStringContainsString('Tank capacity', $shown);
        self::assertStringContainsString('Plug-in hybrid', self::body($browser->get('/garage')));
    }

    public function testEachLogsAFillUpAndAChargeWithItsOwnPicker(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $hybrid = $this->vehicle($app, 'Toyota', 'Corolla', fuel: FuelType::Hybrid);
        $phev = $this->vehicle($app, 'Mitsubishi', 'Outlander', fuel: FuelType::Phev);

        $hybridForm = self::body($browser->get('/vehicles/' . $hybrid->id . '/fuel/new'));
        self::assertStringNotContainsString('<optgroup label="Electricity">', $hybridForm, 'no charging group of its own');
        self::assertMatchesRegularExpression('~<optgroup label="Other fuels">.*<option value="ev:home">~s', $hybridForm);

        $phevForm = self::body($browser->get('/vehicles/' . $phev->id . '/fuel/new'));
        self::assertMatchesRegularExpression(
            '~<optgroup label="Petrol">.*<optgroup label="Electricity">.*<optgroup label="Other fuels">~s',
            $phevForm,
        );

        foreach ([$hybrid, $phev] as $vehicle) {
            $base = '/vehicles/' . $vehicle->id . '/fuel/new';
            self::assertSame(303, $browser->post($base, ['fuel' => 'petrol:e10_95'] + self::FILL)->getStatusCode());
            $charge = ['fuel' => 'ev:home', 'filled_at' => '2026-07-02T19:00', 'odometer' => '10050'];
            $charge += ['volume' => '9', 'price' => '0.3'];
            self::assertSame(303, $browser->post($base, $charge + self::FILL)->getStatusCode());

            $entries = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
            self::assertSame([Fuel::Petrol, Fuel::Electricity], array_map(static fn ($e): Fuel => $e->data->fuel, $entries));
        }

        // A hybrid that was charged lists the charge under "Used on this vehicle".
        $hybridForm = self::body($browser->get('/vehicles/' . $hybrid->id . '/fuel/new'));
        self::assertMatchesRegularExpression('~<optgroup label="Used on this vehicle">.*?<option value="ev:home"~s', $hybridForm);
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    private function vehicles(App $app): array
    {
        $vehicles = $this->service($app, VehicleRepository::class)->listForUser($this->owner($app)->id, false);
        usort($vehicles, static fn (Vehicle $a, Vehicle $b): int => $a->id <=> $b->id);

        return $vehicles;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function find(App $app, Vehicle $vehicle): Vehicle
    {
        $found = $this->service($app, VehicleRepository::class)->find($vehicle->userId, $vehicle->id);
        self::assertNotNull($found);

        return $found;
    }
}
