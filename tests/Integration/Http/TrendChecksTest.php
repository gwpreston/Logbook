<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ReminderTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * The trend and cost checks end to end (spec.md §7.24 items 7–9,
 * docs/phases/phase-25.md): economy drift with its causes, a price and a
 * cost with an extra digit, fixing and hiding, the owner's thresholds,
 * module toggles and who sees what. The owner uses UK units (mpg, miles)
 * in London; "today" is 1 Oct 2026.
 */
final class TrendChecksTest extends ReminderTestCase
{
    private const string NOW = '2026-10-01T10:00:00Z';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private int $km = 10000;

    public function testFiveThirstierTanksAreOneItemWithBothFiguresAndTheCausesThatApply(): void
    {
        $this->start();
        $golf = $this->thirsty();

        $items = $this->items($golf);
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertStringContainsString(
            'Economy is about 13% worse over the last 5 tanks than your 12-month average (49.1 mpg against 56.5 mpg)',
            $item,
            '5.75 against 5.0 L/100 km is 15% more fuel; in mpg it reads 13%',
        );
        self::assertStringContainsString('This may include the time of year', $item, 'no data for these months last year');
        self::assertStringContainsString('You’ve switched from E10 95 to E5 98.', $item);
        self::assertStringContainsString('Also worth checking: tyre pressures, load and roof boxes.', $item);
        foreach (['New tyres', 'Winter usually', 'A service is overdue', 'Shorter tanks'] as $absent) {
            self::assertStringNotContainsString($absent, $item);
        }
        self::assertStringContainsString('/vehicles/' . $golf->id . '/fuel#economy-liquid', $item);
        self::assertStringContainsString('View economy', $item);
        self::assertStringContainsString('name="kind" value="drift_liquid"', $item);
    }

    public function testAHiddenDriftComesBackWithTheNextTank(): void
    {
        $this->start();
        $golf = $this->thirsty();
        $overview = '/vehicles/' . $golf->id;

        $form = $this->hideForm(self::body($this->browser->get($overview)));
        self::assertSame(303, $this->browser->post($overview . '/attention/hide', $form)->getStatusCode());
        self::assertSame([], $this->items($golf));

        $this->fill($golf, '2026-09-28', 600, '34.500', FuelGrade::E5_98);
        self::assertCount(1, $this->items($golf), 'a new tank re-judges');
    }

    public function testAPriceWithAnExtraDigitIsFlaggedAndFixingItRemovesIt(): void
    {
        $this->start();
        $polo = $this->vehicle($this->app, 'Polo');
        $this->fill($polo, '2026-08-20', 500, '30.000');
        $this->fill($polo, '2026-08-30', 500, '30.000');
        $this->fill($polo, '2026-09-05', 500, '30.000');
        $typo = $this->fill($polo, '2026-09-12', 500, '30.000', price: '14.000');
        $this->fill($polo, '2026-09-20', 500, '30.000');

        $items = $this->items($polo);
        self::assertCount(1, $items);
        self::assertStringContainsString('Fill-up on 12 Sept 2026: £14.00/L, about 10× your usual £1.40/L', $items[0]);
        self::assertStringContainsString('An extra or missing digit? Check the price or the volume.', $items[0]);
        self::assertStringContainsString('/vehicles/' . $polo->id . '/fuel/' . $typo->id . '/edit', $items[0]);
        self::assertStringContainsString('Looks right', $items[0]);

        $this->service($this->app, FuelService::class)->update($polo, $typo, $this->priced($typo, '1.400'));
        self::assertSame([], $this->items($polo));
    }

    public function testLooksRightHidesAPriceUntilTheFillUpIsEdited(): void
    {
        $this->start();
        $polo = $this->vehicle($this->app, 'Polo');
        $this->fill($polo, '2026-08-20', 500, '30.000');
        $this->fill($polo, '2026-08-30', 500, '30.000');
        $this->fill($polo, '2026-09-05', 500, '30.000');
        $dear = $this->fill($polo, '2026-09-12', 500, '30.000', price: '2.100');
        $this->fill($polo, '2026-09-20', 500, '30.000');
        $overview = '/vehicles/' . $polo->id;

        $html = self::body($this->browser->get($overview));
        self::assertStringContainsString('about 50% above your usual', $html);
        $form = $this->hideForm($html);
        self::assertSame('fuel_price', $form['kind']);
        self::assertSame((string) $dear->id, $form['subject']);
        self::assertSame(303, $this->browser->post($overview . '/attention/hide', $form)->getStatusCode());
        self::assertSame([], $this->items($polo));

        $data = $dear->data;
        $this->service($this->app, FuelService::class)->update($polo, $dear, new FuelEntryData(
            $data->filledAt,
            $data->odometerKm,
            $data->fuel,
            '31.000',
            $data->pricePerUnit,
            $data->totalCost,
        ));
        self::assertCount(1, $this->items($polo), 'the volume changed: judged again');
    }

    public function testACostWithAnExtraDigitIsFlaggedAndFixingItRemovesIt(): void
    {
        $this->start();
        $polo = $this->vehicle($this->app, 'Polo');
        foreach ([['2024-01-15', '200'], ['2024-06-15', '212'], ['2025-01-15', '230']] as [$date, $cost]) {
            $this->record($polo, $date, $cost);
        }
        $typo = $this->record($polo, '2026-03-14', '2120');

        $items = $this->items($polo);
        self::assertCount(1, $items);
        self::assertStringContainsString('Service on 14 Mar 2026 cost £2,120.00, about 10× your usual £212.00', $items[0]);
        self::assertStringContainsString('An extra or missing digit? Check the amount.', $items[0]);
        self::assertStringContainsString('/vehicles/' . $polo->id . '/maintenance/' . $typo->id . '/edit', $items[0]);

        $data = $typo->data;
        $this->service($this->app, MaintenanceService::class)->update(
            $polo,
            $typo,
            new MaintenanceEntryData($data->performedOn, $data->category, $data->title, '212'),
            new DateTimeZone('Europe/London'),
        );
        self::assertSame([], $this->items($polo));
    }

    public function testTheOwnersThresholdsDecideWhoeverLooks(): void
    {
        $this->start();
        $polo = $this->vehicle($this->app, 'Polo');
        foreach ([['2024-01-15', '200'], ['2024-06-15', '212'], ['2025-01-15', '230']] as [$date, $cost]) {
            $this->record($polo, $date, $cost);
        }
        $this->record($polo, '2026-03-14', '2120');
        $manager = $this->share($polo, ShareLevel::Manage, 'manager');
        self::assertCount(1, $this->items($polo, $manager));

        $this->saveChecks(['cost_multiple' => '20']);
        self::assertSame([], $this->items($polo), '10× is not more than 20×');
        self::assertSame([], $this->items($polo, $manager), 'the owner’s setting, not the manager’s');

        $html = self::body($this->browser->get('/settings/reminders'));
        self::assertMatchesRegularExpression('~name="cost_multiple"[^>]*value="20"~', $html);
        self::assertMatchesRegularExpression('~name="drift_percent_electric"[^>]*value="15"~', $html);
    }

    public function testSwitchedOffModulesTakeTheirChecks(): void
    {
        $this->start();
        $golf = $this->thirsty();
        $this->record($golf, '2024-01-15', '200');
        $this->record($golf, '2024-06-15', '212');
        $this->record($golf, '2025-01-15', '230');
        $this->record($golf, '2026-03-14', '2120');
        self::assertCount(2, $this->items($golf));

        $this->switchOff(Feature::Fuel);
        $items = $this->items($golf);
        self::assertCount(1, $items);
        self::assertStringContainsString('Service on', $items[0]);

        $this->switchOff(Feature::Maintenance);
        $items = $this->items($golf);
        self::assertCount(1, $items);
        self::assertStringContainsString('Economy is about', $items[0]);
    }

    public function testEachPersonSeesWhatTheyCouldFix(): void
    {
        $this->start();
        $golf = $this->thirsty();
        // Top-ups after the last full tank: they close no segment, so the
        // drift is unchanged.
        $this->fill($golf, '2026-09-15', 10, '1.000', FuelGrade::E5_98, partial: true);
        $this->fill($golf, '2026-09-20', 10, '1.000', FuelGrade::E5_98, partial: true);
        $price = $this->fill($golf, '2026-09-26', 10, '1.000', FuelGrade::E5_98, price: '14.000', partial: true);
        $this->record($golf, '2024-01-15', '200');
        $this->record($golf, '2024-06-15', '212');
        $this->record($golf, '2025-01-15', '230');
        $cost = $this->record($golf, '2026-03-14', '2120');
        self::assertCount(3, $this->items($golf), 'drift, price and cost');

        $viewer = $this->share($golf, ShareLevel::View, 'viewer');
        $driver = $this->share($golf, ShareLevel::Log, 'driver');
        self::assertSame([], $this->items($golf, $viewer), 'View: no checks');

        $items = $this->items($golf, $driver);
        self::assertCount(1, $items, 'Log: the drift, not the owner’s entries');
        self::assertStringContainsString('Economy is about', $items[0]);

        $id = $this->connection($this->app)->fetchOne('SELECT id FROM users WHERE username = ?', ['driver']);
        $this->connection($this->app)->update('fuel_entries', ['created_by' => $id], ['id' => $price->id]);
        $this->connection($this->app)->update('maintenance_entries', ['created_by' => $id], ['id' => $cost->id]);
        self::assertCount(3, $this->items($golf, $driver), 'and their own fill-up and record');
    }

    public function testAShareWithoutCostsSeesNoPriceOrCostEvenOnTheirOwnEntries(): void
    {
        $this->start();
        $golf = $this->thirsty();
        $this->fill($golf, '2026-09-15', 10, '1.000', FuelGrade::E5_98, partial: true);
        $this->fill($golf, '2026-09-20', 10, '1.000', FuelGrade::E5_98, partial: true);
        $price = $this->fill($golf, '2026-09-26', 10, '1.000', FuelGrade::E5_98, price: '14.000', partial: true);
        $this->record($golf, '2024-01-15', '200');
        $this->record($golf, '2024-06-15', '212');
        $this->record($golf, '2025-01-15', '230');
        $cost = $this->record($golf, '2026-03-14', '2120');

        $driver = $this->share($golf, ShareLevel::Log, 'driver', costs: false);
        $id = $this->connection($this->app)->fetchOne('SELECT id FROM users WHERE username = ?', ['driver']);
        $this->connection($this->app)->update('fuel_entries', ['created_by' => $id], ['id' => $price->id]);
        $this->connection($this->app)->update('maintenance_entries', ['created_by' => $id], ['id' => $cost->id]);

        // Their own entries' amounts they may see, but "your usual" is made
        // from everyone's (EntryAccess::canSeeAmount): no price or cost item.
        $items = $this->items($golf, $driver);
        self::assertCount(1, $items, 'the drift shows no amounts; the price and cost do');
        self::assertStringContainsString('Economy is about', $items[0]);
        self::assertStringNotContainsString('£', implode('', $items));
        self::assertCount(3, $this->items($golf), 'the owner sees all three');
    }

    public function testATyreFittingIsACauseOnItsCalendarDateWestOfUtc(): void
    {
        $this->start();
        $this->connection($this->app)->update('users', ['timezone' => 'America/New_York'], ['username' => 'owner']);
        $golf = $this->thirsty();
        $this->connection($this->app)->insert('tyre_changes', [
            'vehicle_id' => $golf->id,
            'kind' => 'fit',
            'done_on' => '2026-08-03',
            'created_at' => '2026-08-03 12:00:00',
            'updated_at' => '2026-08-03 12:00:00',
        ]);

        $items = $this->items($golf);
        self::assertCount(1, $items);
        self::assertStringContainsString('New tyres were fitted on 3 Aug 2026.', $items[0], 'not 2 Aug');
    }

    // --- Helpers -----------------------------------------------------------

    private function start(): void
    {
        $this->app = $this->createRecordingApp(self::CHANNELS);
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->km = 10000;
    }

    /**
     * Eight tanks at 5.0 L/100 km on E10 95 from November, then five at
     * 5.75 on E5 98 from July: 15% more fuel per mile.
     */
    private function thirsty(): Vehicle
    {
        $golf = $this->vehicle($this->app);
        $start = new DateTimeImmutable('2025-11-01T08:00:00Z');
        $this->fill($golf, $start->modify('-28 days')->format('Y-m-d'), 0, '40.000', FuelGrade::E10_95);
        for ($i = 0; $i < 8; $i++) {
            // The last baseline fill opens the first recent segment: E5 98.
            $grade = $i === 7 ? FuelGrade::E5_98 : FuelGrade::E10_95;
            $this->fill($golf, $start->modify('+' . ($i * 28) . ' days')->format('Y-m-d'), 600, '30.000', $grade);
        }
        $recent = new DateTimeImmutable('2026-07-01T08:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $this->fill($golf, $recent->modify('+' . ($i * 18) . ' days')->format('Y-m-d'), 600, '34.500', FuelGrade::E5_98);
        }

        return $golf;
    }

    private function fill(
        Vehicle $vehicle,
        string $date,
        int $distance,
        string $litres,
        ?FuelGrade $grade = null,
        string $price = '1.400',
        bool $partial = false,
    ): FuelEntry {
        $this->km += $distance;

        return $this->service($this->app, FuelService::class)->create(
            $vehicle,
            new FuelEntryData(
                new DateTimeImmutable($date . 'T08:00:00Z'),
                (string) $this->km,
                Fuel::Petrol,
                $litres,
                $price,
                '0',
                $partial,
                grade: $grade,
            ),
        );
    }

    private function priced(FuelEntry $entry, string $price): FuelEntryData
    {
        $d = $entry->data;

        return new FuelEntryData(
            $d->filledAt,
            $d->odometerKm,
            $d->fuel,
            $d->volume,
            $price,
            $d->totalCost,
            $d->isPartial,
            grade: $d->grade,
        );
    }

    private function record(Vehicle $vehicle, string $date, string $cost): MaintenanceEntry
    {
        return $this->service($this->app, MaintenanceService::class)->create(
            $vehicle,
            new MaintenanceEntryData(
                new DateTimeImmutable($date, new DateTimeZone('UTC')),
                MaintenanceCategory::Service,
                'Annual service',
                $cost,
            ),
            new DateTimeZone('Europe/London'),
        );
    }

    private function switchOff(Feature $off): void
    {
        $this->service($this->app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== $off && $f !== Feature::Trips,
        )));
    }

    /**
     * @param array<string, string> $checks
     */
    private function saveChecks(array $checks): void
    {
        $response = $this->browser->post('/settings/reminders', $checks + [
            'schedule_days' => '30',
            'schedule_distance' => '621',
            'document_days' => '30',
            'manual_days' => '7',
        ]);
        self::assertSame(303, $response->getStatusCode());
        $this->browser->get('/settings/reminders');
    }

    private function share(Vehicle $vehicle, ShareLevel $level, string $username, bool $costs = true): TestBrowser
    {
        $this->createMember($this->app, $username);
        self::assertNull($this->service($this->app, SharingService::class)->add($vehicle, $username, $level, $costs, false));

        return $this->browserFor($this->app, $username);
    }

    /**
     * The overview card's trend and cost items, as HTML.
     *
     * @phpstan-impure
     * @return list<string>
     */
    private function items(Vehicle $vehicle, ?TestBrowser $as = null): array
    {
        $document = Html::document(self::body(($as ?? $this->browser)->get('/vehicles/' . $vehicle->id)));
        $items = [];
        foreach ($document->querySelectorAll('section.card--attention li.attention-item') as $item) {
            $html = $document->saveHtml($item);
            if (preg_match('~Economy is about|Fill-up on|Charge on|cost £~', $html) === 1) {
                $items[] = $html;
            }
        }

        return $items;
    }

    /**
     * The first Hide form's fields on the page.
     *
     * @return array<string, string>
     */
    private function hideForm(string $html): array
    {
        $document = Html::document($html);
        $values = Html::formValues(Html::element($document, 'form[action$="/attention/hide"]'));
        unset($values['csrf_name'], $values['csrf_value']);

        return $values;
    }
}
