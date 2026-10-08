<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Dom\Element;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Incident\DamageArea;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Tyre\TyreChangeData;
use Logbook\Domain\Tyre\TyreData;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Tyre\NewTyre;
use Logbook\Service\Tyre\TyreChangeService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * An edit page shows stored km and litres in miles and US gallons, and a
 * value converted back can land a step off (40 800 km → 25 351.945 mi →
 * 40 800.001 km). Saving the form with only the notes changed keeps every
 * stored column as it was (spec.md §8 *Units*), the synced odometer
 * reading included. Each form is posted as the page renders it.
 */
final class ImperialEditKeepsStoredTest extends AppTestCase
{
    use CostFixtures;

    /** The tables an entry edit can touch. */
    private const array TABLES = [
        'fuel_entries', 'odometer_readings', 'maintenance_entries', 'compliance_documents', 'trips', 'incidents',
        'tyre_changes', 'reminders',
    ];
    /** 40 800 km is 25 351.945 mi on the page, and 40 800.001 km if converted back. */
    private const string KM = '40800';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $golf;
    private DateTimeZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($this->app, '2026-09-30T12:00:00Z');
        $this->resetDatabase($this->app);
        $this->createOwner($this->app, preferences: new DisplayPreferences(
            'en_US',
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'GBP',
        ));
        $this->browser = new TestBrowser($this->app);
        $this->browser->get('/login');
        self::assertSame(303, $this->browser->post('/login', ['username' => 'owner', 'password' => self::PASSWORD])
            ->getStatusCode());
        $this->golf = $this->vehicle($this->app);
        $this->zone = new DateTimeZone('Europe/London');
    }

    public function testAFillUpKeepsItsOdometerVolumeAndPrice(): void
    {
        // 42.123 L is 11.128 US gal, and 42.124 L back; the price per litre moves too.
        $fill = $this->fillUp($this->app, $this->golf, '2026-08-01T07:30:00Z', self::KM, '42.123', '61.37');

        $this->assertNotesOnlyEditKeepsEverything('/fuel/' . $fill->id . '/edit', 'notes');
    }

    public function testAReadingKeepsItsKm(): void
    {
        $this->reading($this->app, $this->golf, self::KM, '2026-09-20T12:00:00Z');
        $reading = $this->service($this->app, OdometerService::class)->history($this->golf)->latest();
        self::assertNotNull($reading);

        $this->assertNotesOnlyEditKeepsEverything('/odometer/' . $reading->id . '/edit', 'note');
    }

    public function testAServiceRecordKeepsItsOdometer(): void
    {
        $entry = $this->service($this->app, MaintenanceService::class)->create($this->golf, new MaintenanceEntryData(
            new DateTimeImmutable('2026-09-05', new DateTimeZone('UTC')),
            MaintenanceCategory::Service,
            'Annual service',
            '187.43',
            self::KM,
        ), $this->zone);

        $this->assertNotesOnlyEditKeepsEverything('/maintenance/' . $entry->id . '/edit', 'description');
    }

    public function testADocumentKeepsItsOdometer(): void
    {
        $document = $this->service($this->app, ComplianceService::class)->create($this->golf, new ComplianceDocumentData(
            ComplianceType::Inspection,
            null,
            'Test centre',
            null,
            new DateTimeImmutable('2025-10-15', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-14', new DateTimeZone('UTC')),
            '54.85',
            odometerKm: self::KM,
        ), $this->zone);

        $this->assertNotesOnlyEditKeepsEverything('/documents/' . $document->id . '/edit', 'notes');
    }

    public function testAnIncidentKeepsItsOdometer(): void
    {
        $incident = $this->service($this->app, IncidentService::class)->create($this->golf, new IncidentData(
            new DateTimeImmutable('2026-03-14', new DateTimeZone('UTC')),
            IncidentType::ParkedDamage,
            damageAreas: [DamageArea::Rear],
        ), self::KM, $this->zone);

        $this->assertNotesOnlyEditKeepsEverything('/incidents/' . $incident->id . '/edit', 'notes');
    }

    public function testATyreChangeKeepsItsOdometer(): void
    {
        $change = $this->service($this->app, TyreChangeService::class)->existing(
            $this->golf,
            new TyreChangeData(new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')), self::KM),
            [new NewTyre(TyrePosition::FrontLeft, new TyreData('Michelin', 'Primacy 4'), '8.000')],
            $this->zone,
            'en_GB',
        );

        $this->assertNotesOnlyEditKeepsEverything('/tyres/changes/' . $change->id . '/edit', 'note');
    }

    public function testATripWithOdometersKeepsThemAndItsDistance(): void
    {
        $trip = $this->service($this->app, TripService::class)->create($this->golf, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            false,
            '90.123',
            odometerStartKm: self::KM,
            odometerEndKm: '40890.123',
            purpose: 'Site visit',
        ));

        $this->assertNotesOnlyEditKeepsEverything('/trips/' . $trip->id . '/edit', 'notes');
    }

    public function testAReturnTripWithoutOdometersKeepsItsDistance(): void
    {
        // 40 800 km whole is 12 675.973 mi each way, 40 800.001 km doubled back.
        $trip = $this->service($this->app, TripService::class)->create($this->golf, new TripData(
            new DateTimeImmutable('2026-09-10', new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            true,
            self::KM,
            purpose: 'Site visit',
        ));

        $this->assertNotesOnlyEditKeepsEverything('/trips/' . $trip->id . '/edit', 'notes');
    }

    /**
     * Open the edit page, change only the text field, save, and compare
     * every row the vehicle owns (bar that field and `updated_at`).
     */
    private function assertNotesOnlyEditKeepsEverything(string $path, string $textField): void
    {
        $path = '/vehicles/' . $this->golf->id . $path;
        $before = $this->snapshot();

        $page = $this->browser->get($path);
        self::assertSame(200, $page->getStatusCode(), $path);
        $form = $this->editForm((string) $page->getBody(), $textField);
        $fields = self::fields($form);
        $fields[$textField] = 'Only the notes changed';

        $saved = $this->browser->post($path, $fields, withCsrf: false);
        self::assertSame(303, $saved->getStatusCode(), $path . ': ' . strip_tags((string) $saved->getBody()));
        self::assertSame($before, $this->snapshot(), $path . ': a notes-only edit changes no stored value');
    }

    private function editForm(string $html, string $textField): Element
    {
        foreach (Html::document($html)->querySelectorAll('form') as $form) {
            if ($form->querySelector('[name="' . $textField . '"]') !== null) {
                return $form;
            }
        }
        self::fail('no form with a ' . $textField . ' field');
    }

    /**
     * What a browser submits for the form as it stands; `name[]` fields as lists.
     *
     * @return array<string, string|list<string>>
     */
    private static function fields(Element $form): array
    {
        $fields = [];
        foreach ($form->querySelectorAll('input[name], select[name], textarea[name]') as $control) {
            $name = (string) $control->getAttribute('name');
            $type = strtolower((string) $control->getAttribute('type'));
            if ($control->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }
            if ($control->localName === 'select') {
                $selected = $control->querySelector('option[selected]') ?? $control->querySelector('option');
                $value = (string) $selected?->getAttribute('value');
            } elseif ($control->localName === 'textarea') {
                $value = (string) $control->textContent;
            } else {
                $value = (string) $control->getAttribute('value');
            }
            if (str_ends_with($name, '[]')) {
                $list = $fields[substr($name, 0, -2)] ?? [];
                $fields[substr($name, 0, -2)] = [...(is_array($list) ? $list : []), $value];
                continue;
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * Every row the vehicle owns in the tables an edit can touch, as stored,
     * without the free-text fields and `updated_at`.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $rows = $this->connection($this->app)->createQueryBuilder()->select('*')->from($table)
                ->where('vehicle_id = :vehicle')->orderBy('id')
                ->setParameter('vehicle', $this->golf->id)->fetchAllAssociative();
            $snapshot[$table] = array_map(static function (array $row): array {
                unset($row['notes'], $row['note'], $row['description'], $row['updated_at']);

                return $row;
            }, $rows);
        }

        return $snapshot;
    }
}
