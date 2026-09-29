<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Tyre\Tyre;
use Logbook\Domain\Tyre\TyreStatus;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Tyre\TyreService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The tyre pages (spec.md §7.17) through HTTP: each change kind through its
 * form, as a page and in the desktop modal, the Tyres tab, the module
 * toggles and the vehicle type check. Every form is a plain POST, so these
 * are also the no-JS paths.
 */
final class TyrePagesTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-29T10:00:00Z';
    private const array MODAL = ['X-Logbook-Modal' => '1'];

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $car;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->car = $this->vehicle($this->app);
        $this->reading($this->app, $this->car, '32186.880', '2026-09-01T09:00:00Z');
        $this->base = '/vehicles/' . $this->car->id . '/tyres';
    }

    /**
     * @return array<string, Tyre> position → fitted tyre
     */
    private function fitted(?Vehicle $vehicle = null): array
    {
        $fitted = [];
        foreach ($this->service($this->app, TyreService::class)->tyres($vehicle ?? $this->car) as $tyre) {
            if ($tyre->status === TyreStatus::Fitted && $tyre->position !== null) {
                $fitted[$tyre->position->value] = $tyre;
            }
        }

        return $fitted;
    }

    /**
     * Record the four road tyres already on the car (a no-JS form post).
     */
    private function startWithExistingTyres(): void
    {
        $form = [
            'done_on' => '2025-10-03',
            'odometer' => '20000',
            'note' => '',
        ];
        foreach (['fl', 'fr', 'rl', 'rr'] as $position) {
            $form += [
                'pos_' . $position => '1',
                'brand_' . $position => 'Goodyear',
                'model_' . $position => 'EfficientGrip',
                'size_' . $position => '205/55 r16  91v',
                'season_' . $position => '',
                'dot_' . $position => '1223',
            ];
        }
        $response = $this->browser->post($this->base . '/existing', $form);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
    }

    public function testAnEmptyTabOffersTheTyresAlreadyOnTheVehicleFirst(): void
    {
        $html = self::body($this->browser->get($this->base));

        self::assertStringContainsString('No tyres recorded yet', $html);
        $existing = strpos($html, 'href="' . $this->base . '/existing"');
        $fit = strpos($html, 'href="' . $this->base . '/fit"');
        self::assertNotFalse($existing);
        self::assertNotFalse($fit);
        self::assertLessThan($fit, $existing, 'Tyres already on the vehicle comes first');
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('>Tyres</a>', $html, 'the Tyres tab');
    }

    public function testTyresAlreadyOnTheVehicleThroughTheForm(): void
    {
        $form = self::body($this->browser->get($this->base . '/existing'));
        $hint = 'If you don’t know when they were fitted, leave today’s reading: distance counts from now.';
        self::assertStringContainsString($hint, $form);
        $prefilled = 'name="odometer" type="number" value="20000"';
        self::assertStringContainsString($prefilled, $form, 'prefilled with the latest reading');
        self::assertStringContainsString('name="pos_fl" value="1" checked', $form, 'road positions ticked');
        self::assertStringNotContainsString('name="pos_spare" value="1" checked', $form, 'the spare is not');

        $this->startWithExistingTyres();

        $fitted = $this->fitted();
        self::assertCount(4, $fitted);
        self::assertSame('205/55 R16 91V', $fitted['fl']->data->size, 'size normalised');
        self::assertNull($fitted['fl']->data->season, 'blank season is not specified');
        self::assertSame('2023-03-20', $fitted['fl']->data->dot?->manufacturedOn->format('Y-m-d'));

        $html = self::body($this->browser->get($this->base));
        self::assertStringContainsString('Front left', $html);
        self::assertStringContainsString('Goodyear EfficientGrip', $html);
        self::assertStringContainsString('since 3 Oct 2025', $html, 'distance counts since the first record');
        self::assertStringContainsString('Age 3 yrs 6 mo', $html);
        self::assertStringContainsString('Recorded 4 tyres (all four)', $html);
    }

    public function testFitTyresWithACostWritesOneServiceRecordAndOneReading(): void
    {
        $this->startWithExistingTyres();
        $response = $this->browser->post($this->base . '/fit', [
            'done_on' => '2026-09-20',
            'odometer' => '21000',
            'pos_fl' => '1',
            'pos_fr' => '1',
            'brand' => 'Michelin',
            'model' => 'Primacy 4',
            'size' => '205/55 R16 91V',
            'season' => 'summer',
            'dot_fl' => '3025',
            'dot_fr' => '3125',
            'replace_fl' => 'worn',
            'replace_fr' => 'store',
            'cost' => '240',
            'vendor' => 'Kwik Fit',
            'note' => '',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertStringContainsString('Tyres fitted.', self::body($this->browser->follow($response)));

        $records = $this->service($this->app, MaintenanceEntryRepository::class)->listForVehicle($this->car->id);
        self::assertCount(1, $records);
        self::assertSame(MaintenanceCategory::Tyres, $records[0]->data->category);
        self::assertSame('2 × Michelin Primacy 4, front', $records[0]->data->title);
        $sources = array_map(
            static fn ($r): string => $r->source->value,
            $this->service($this->app, OdometerReadingRepository::class)->listForVehicle($this->car->id),
        );
        self::assertSame(['tyre', 'manual', 'maintenance'], $sources, 'the fit writes no reading of its own');

        $html = self::body($this->browser->get($this->base));
        self::assertStringContainsString('Fitted 2 × Michelin Primacy 4 (front)', $html);
        self::assertStringContainsString('£240.00', $html, 'the linked record carries the cost');
        self::assertStringContainsString('Summer', $html);
        self::assertStringContainsString('In storage', $html);
        self::assertStringContainsString('Retired (1)', $html);
        self::assertStringContainsString('Worn out', $html);
    }

    public function testEachKindRendersAsAPageAndInTheModal(): void
    {
        $this->startWithExistingTyres();
        foreach (['existing', 'fit', 'swap', 'rotate', 'repair', 'remove'] as $kind) {
            $page = $this->browser->get($this->base . '/' . $kind);
            self::assertSame(200, $page->getStatusCode(), $kind);
            self::assertStringContainsString('<form class="form" method="post"', self::body($page), $kind);

            $modal = $this->browser->get($this->base . '/' . $kind, self::MODAL);
            self::assertSame(200, $modal->getStatusCode(), $kind);
            $html = self::body($modal);
            self::assertStringNotContainsString('<nav class="tabs"', $html, $kind . ': the modal renders only the form');
            self::assertStringContainsString('action="' . $this->base . '/' . $kind . '"', $html, $kind);
        }
    }

    public function testRotateSwapRepairAndRemoveThroughTheirForms(): void
    {
        $this->startWithExistingTyres();
        $fitted = $this->fitted();

        $rotate = $this->browser->post($this->base . '/rotate', [
            'done_on' => '2026-01-10',
            'odometer' => '20500',
            'move_' . $fitted['fl']->id => 'rl',
            'move_' . $fitted['rl']->id => 'fl',
            'move_' . $fitted['fr']->id => 'rr',
            'move_' . $fitted['rr']->id => 'fr',
        ], headers: self::MODAL);
        self::assertSame(204, $rotate->getStatusCode(), 'a modal save closes the dialog');
        self::assertSame($this->base, $rotate->getHeaderLine('X-Logbook-Location'));
        self::assertSame($fitted['fl']->id, $this->fitted()['rl']->id, 'front left moved to rear left');

        $bad = $this->browser->post($this->base . '/rotate', [
            'done_on' => '2026-01-11',
            'odometer' => '20510',
            'move_' . $fitted['fl']->id => 'fl',
            'move_' . $fitted['rl']->id => 'fl',
            'move_' . $fitted['fr']->id => 'fr',
            'move_' . $fitted['rr']->id => 'rr',
        ], headers: self::MODAL);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('Every position must end up with exactly one tyre', self::body($bad));
        self::assertStringContainsString('value="2026-01-11"', self::body($bad), 'typed values are kept');

        $repair = $this->browser->post($this->base . '/repair', [
            'done_on' => '2026-02-01',
            'odometer' => '',
            'repair_' . $fitted['fl']->id => '1',
        ]);
        self::assertSame(303, $repair->getStatusCode(), self::body($repair));

        $swap = $this->browser->post($this->base . '/swap', [
            'done_on' => '2026-03-01',
            'odometer' => '20800',
            'set' => 'new',
            'set_name' => 'Summer wheels',
            'set_location' => 'Garage loft',
        ]);
        self::assertSame(303, $swap->getStatusCode(), self::body($swap));
        self::assertSame([], $this->fitted(), 'everything on the road came off');
        $html = self::body($this->browser->get($this->base));
        self::assertStringContainsString('Summer wheels', $html);
        self::assertStringContainsString('Kept at Garage loft', $html);

        $form = self::body($this->browser->get($this->base . '/swap'));
        foreach ($fitted as $tyre) {
            self::assertMatchesRegularExpression('/name="on_' . $tyre->id . '"/', $form);
        }
        $swapBack = ['done_on' => '2026-04-01', 'odometer' => '21000'];
        foreach ($fitted as $position => $tyre) {
            $swapBack['on_' . $tyre->id] = $position;
        }
        $response = $this->browser->post($this->base . '/swap', $swapBack);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertCount(4, $this->fitted());

        $remove = $this->browser->post($this->base . '/remove', [
            'done_on' => '2026-05-01',
            'odometer' => '21200',
            'action_' . $fitted['fl']->id => 'damaged',
            'action_' . $fitted['fr']->id => 'store',
            'action_' . $fitted['rl']->id => 'keep',
            'action_' . $fitted['rr']->id => 'keep',
            'set' => '',
        ]);
        self::assertSame(303, $remove->getStatusCode(), self::body($remove));
        self::assertCount(2, $this->fitted());
    }

    public function testEditingAndDeletingAChange(): void
    {
        $this->startWithExistingTyres();
        $changes = $this->service($this->app, TyreService::class)->changes($this->car);
        $edit = $this->base . '/changes/' . $changes[0]->id . '/edit';

        $form = self::body($this->browser->get($edit));
        self::assertStringContainsString('Goodyear EfficientGrip', $form, 'its tyres, read-only');
        $saved = $this->browser->post($edit, ['done_on' => '2025-10-04', 'odometer' => '20100', 'note' => 'Bought with the car']);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
        $moved = $this->service($this->app, TyreService::class)->changes($this->car)[0];
        self::assertSame('2025-10-04', $moved->data->doneOn->format('Y-m-d'));

        $delete = $this->base . '/changes/' . $changes[0]->id . '/delete';
        self::assertStringContainsString('It recorded these tyres', self::body($this->browser->get($delete)));
        $deleted = $this->browser->post($delete);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame([], $this->service($this->app, TyreService::class)->tyres($this->car), 'the tyres it created went too');
    }

    public function testEditingATyreAndASet(): void
    {
        $this->startWithExistingTyres();
        $tyre = $this->fitted()['fl'];

        $bad = $this->browser->post($this->base . '/' . $tyre->id . '/edit', ['brand' => 'Goodyear', 'dot' => '5423']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertStringContainsString('That week does not exist', self::body($bad));

        $ok = $this->browser->post($this->base . '/' . $tyre->id . '/edit', [
            'brand' => 'Goodyear',
            'model' => 'EfficientGrip 2',
            'season' => 'winter',
            'dot' => '',
        ]);
        self::assertSame(303, $ok->getStatusCode(), self::body($ok));
        $edited = $this->fitted()['fl'];
        self::assertSame('EfficientGrip 2', $edited->data->model);
        self::assertSame(TyreStatus::Fitted, $edited->status, 'the state is not the edit form\'s');
    }

    public function testTheTyresModuleOffRemovesItEverywhereAndKeepsTheReadings(): void
    {
        $this->startWithExistingTyres();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save([Feature::Fuel, Feature::Maintenance, Feature::Compliance, Feature::Reminders, Feature::Reports]);

        foreach (['', '/fit', '/existing', '/rotate'] as $path) {
            self::assertSame(404, $this->browser->get($this->base . $path)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->browser->get('/vehicles/' . $this->car->id . '/export/tyres.csv')->getStatusCode());
        self::assertSame(404, $this->browser->get('/log/new/tyre')->getStatusCode());
        $overview = self::body($this->browser->get('/vehicles/' . $this->car->id));
        self::assertStringNotContainsString($this->base, $overview, 'no tab, no card');
        self::assertStringNotContainsString('Tyre change', self::body($this->browser->get('/log/new')));
        $mileage = self::body($this->browser->get('/vehicles/' . $this->car->id . '/odometer'));
        self::assertStringContainsString('20,000 mi', $mileage, 'the tyre reading stays in the mileage log');

        $toggles->save(Feature::cases());
        self::assertSame(200, $this->browser->get($this->base)->getStatusCode());
        self::assertCount(4, $this->fitted(), 'switching it back on restores everything');
    }

    public function testMaintenanceOffHidesTheCostFieldsAndChangesStillSave(): void
    {
        $this->startWithExistingTyres();
        $toggles = $this->service($this->app, FeatureToggles::class);
        $toggles->save(array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f !== Feature::Maintenance)));

        $form = self::body($this->browser->get($this->base . '/fit'));
        self::assertStringNotContainsString('name="cost"', $form);
        self::assertStringNotContainsString('name="link"', $form);

        $response = $this->browser->post($this->base . '/fit', [
            'done_on' => '2026-09-20',
            'odometer' => '21000',
            'pos_fl' => '1',
            'brand' => 'Michelin',
            'replace_fl' => 'worn',
            'cost' => '120',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertSame([], $this->service($this->app, MaintenanceEntryRepository::class)->listForVehicle($this->car->id));
    }

    public function testTheVehicleTypeChangeIsRefusedWhileRearTyresAreFitted(): void
    {
        $this->startWithExistingTyres();
        $data = $this->car->data;

        $response = $this->browser->post('/vehicles/' . $this->car->id . '/edit', [
            'type' => 'bike',
            'make' => $data->make,
            'model' => 'Golf GTI',
            'fuel_type' => $data->fuelType->value,
            'registration' => (string) $data->registration,
        ]);

        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('Remove the tyres first: a motorbike has no front left wheel.', $html);
        self::assertStringContainsString('value="Golf GTI"', $html, 'the typed values are kept');
        $vehicles = $this->service($this->app, \Logbook\Service\Vehicle\VehicleService::class);
        self::assertSame(VehicleType::Car, $vehicles->get($this->owner($this->app), $this->car->id)->data->type);
    }

    public function testTheLogEntryChooserOpensFitTyres(): void
    {
        self::assertStringContainsString('Tyre change', self::body($this->browser->get('/log/new')));
        $response = $this->browser->get('/log/new/tyre');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame($this->base . '/fit', $response->getHeaderLine('Location'));
    }

    public function testAMotorbikeHasFrontAndRear(): void
    {
        $bike = $this->vehicle($this->app, 'Honda', 'CB500F', type: VehicleType::Bike);
        $base = '/vehicles/' . $bike->id . '/tyres';
        $form = self::body($this->browser->get($base . '/existing'));
        self::assertStringContainsString('name="pos_front"', $form);
        self::assertStringContainsString('name="pos_rear"', $form);
        self::assertStringNotContainsString('name="pos_fl"', $form);

        $response = $this->browser->post($base . '/existing', [
            'done_on' => '2026-06-01',
            'odometer' => '5000',
            'pos_front' => '1',
            'brand_front' => 'Michelin',
            'model_front' => 'Road 6',
            'size_front' => '120/70 ZR17',
            'pos_rear' => '1',
            'brand_rear' => 'Michelin',
            'model_rear' => 'Road 6',
            'size_rear' => '160/60 ZR17',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));
        self::assertSame(['front', 'rear'], array_keys($this->fitted($bike)));

        $fit = $this->browser->post($base . '/fit', [
            'done_on' => '2026-09-01',
            'odometer' => '9000',
            'pos_rear' => '1',
            'brand' => 'Michelin',
            'model' => 'Road 6',
            'size' => '160/60 ZR17',
            'replace_rear' => 'worn',
        ]);
        self::assertSame(303, $fit->getStatusCode(), self::body($fit));
        $html = self::body($this->browser->get($base));
        self::assertStringContainsString('Rear', $html);
        self::assertStringContainsString('Retired (1)', $html);
    }
}
