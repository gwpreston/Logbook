<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Import;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\MaintenanceScheduleRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Import\App\AppFiles;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\Tracktor\TracktorExport;
use Logbook\Service\Import\App\Tracktor\TracktorImporter;
use Logbook\Service\Import\App\Tracktor\TracktorImportOptions;
use Logbook\Service\Import\App\Tracktor\TracktorPreview;
use Logbook\Service\Import\App\Tracktor\TracktorReader;
use Logbook\Service\Import\App\Tracktor\TracktorVehiclePreview as V;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use Throwable;

/**
 * Importing from Tracktor (spec.md §7.13 *The Tracktor reader*, Phase 51)
 * through the importer service, with real exports of Tracktor 2.1.0 and
 * 1.4.1: a whole garage with its files, re-imports, a newer export, the
 * gallon, one transaction, and the owner's decisions #463–#472.
 */
final class TracktorImportTest extends AppTestCase
{
    private const string NOW = '2026-10-16T09:00:00Z';

    public function testTheMetricGarageImportsCompletelyWithItsFiles(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(self::indian());
        $export = self::fixture('export-metric.json');
        $files = $this->uploads();

        $options = $importer->guess($owner, $export, []);
        $new = TracktorImportOptions::NEW_VEHICLE;
        self::assertSame([$new, $new], array_values($options->vehicles));
        self::assertSame([DistanceUnit::Kilometre, VolumeUnit::Litre], [$options->distanceUnit, $options->volumeUnit]);
        self::assertSame(MaintenanceCategory::Service->value, $options->service);
        self::assertSame(
            ['insurance' => 'insurance', 'emissions' => 'pollution', 'roadworthiness' => 'inspection', 'other' => 'other'],
            $options->documents,
        );
        self::assertTrue(TracktorImporter::unitsFromFile($export));

        $preview = $importer->analyse($owner, $export, $options, [], [], $files);
        [$car, $bike] = $preview->vehicles;
        self::assertSame([8, 3, 3, 2, 1, 4], [
            $car->count(V::FILLS, AppRowStatus::Import),
            $car->count(V::SERVICES, AppRowStatus::Import),
            $car->count(V::DOCUMENTS, AppRowStatus::Import),
            $car->count(V::REMINDERS, AppRowStatus::Import),
            $car->count(V::READINGS, AppRowStatus::Import),
            $car->count(V::FILES, AppRowStatus::Import),
        ]);
        self::assertSame(3, $car->count(V::DETAILS, AppRowStatus::NotImported), 'the colour and two custom fields');
        self::assertSame('import_app.tracktor.reason.no_notes', $car->rows(V::DETAILS)[0]->note['key'] ?? null);
        $expected = ['Maruti Suzuki', 'Swift', 2019, 'KA01AB1234', 'MA3EWDE1S00123456', VehicleType::Car, FuelType::Petrol, null];
        self::assertSame($expected, [
            $car->newVehicle?->make,
            $car->newVehicle?->model,
            $car->newVehicle?->year,
            $car->newVehicle?->registration,
            $car->newVehicle?->vin,
            $car->newVehicle?->type,
            $car->newVehicle?->fuelType,
            $car->newVehicle?->currency,
        ]);
        self::assertSame(VehicleType::Bike, $bike->newVehicle?->type, 'a motorcycle is a bike');
        self::assertSame('INR', $car->currency);
        self::assertSame([], $car->warnings);
        self::assertSame(0, $preview->filesLeftBehind());
        self::assertSame([], $this->manageable($app, $owner), 'a preview writes nothing');

        $sanity = $car->sanity;
        self::assertNotNull($sanity);
        self::assertSame(EnergyKind::Liquid, $sanity->kind);
        self::assertFalse($sanity->isUnusual());

        $done = $importer->import($owner, $preview, $files);
        $files->close();
        $swift = $done->vehicles[0]->written;
        $enfield = $done->vehicles[1]->written;
        self::assertNotNull($swift);
        self::assertNotNull($enfield);
        $stored = $this->service($app, VehicleService::class)->get($owner, $swift->id);
        self::assertNotNull($stored->photoPath, 'the photo came from the uploads');

        // Fill-ups to the paisa, as Tracktor has them.
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($swift->id);
        self::assertCount(8, $fills);
        $total = '0';
        foreach ($fills as $fill) {
            $total = Decimal::add($total, $fill->data->totalCost);
            self::assertSame(Fuel::Petrol, $fill->data->fuel);
        }
        self::assertSame('22033.02', Decimal::trim($total), 'the sum of every cost in the export');
        $at = static function (string $km) use ($fills): FuelEntryData {
            foreach ($fills as $fill) {
                if (Decimal::trim($fill->data->odometerKm) === $km) {
                    return $fill->data;
                }
            }
            self::fail('No fill-up at ' . $km);
        };
        self::assertTrue($at('41890')->isPartial);
        self::assertTrue($at('43150')->isMissedPrevious);
        $kolkata = new \DateTimeZone('Asia/Kolkata');
        $day = static fn (FuelEntryData $f): string => $f->filledAt->setTimezone($kolkata)->format('Y-m-d');
        self::assertSame('2026-01-04', $day($at('41020')));
        self::assertSame('2026-03-30', $day($at('43150')), 'typed in London in summer');
        self::assertSame('27.4', Decimal::trim($at('43600')->volume));
        self::assertSame('3000', Decimal::trim($at('44090')->totalCost));
        self::assertSame('104.2', Decimal::trim($at('44090')->pricePerUnit), 'the volume was derived, as the form does');

        $services = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($swift->id);
        $titles = array_map(
            static fn ($e): array => [$e->data->title, $e->data->vendor, Decimal::trim($e->data->cost), $e->data->category],
            $services,
        );
        self::assertContains(['Periodic service 40,000 km', 'Bluebird Motors', '4850.5', MaintenanceCategory::Service], $titles);
        self::assertContains(
            ['Puncture repair (free under warranty)', 'Quick Tyre Point', '0', MaintenanceCategory::Service],
            $titles,
            'a free service',
        );
        self::assertContains(['Service', 'Bluebird Motors', '1200', MaintenanceCategory::Service], $titles, 'no notes: Service');

        $documents = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($swift->id);
        self::assertSame(
            [[ComplianceType::Insurance, 'Acko General', 'POL-77812345', '2026-03-01', null],
             [ComplianceType::Pollution, 'Shell Indiranagar', 'PUC-KA01-998877', '2026-05-10', '2026-11-09'],
             [ComplianceType::Other, 'ICICI Bank', 'FT-12345', '2025-08-01', null]],
            array_map(static fn ($d): array => [
                $d->data->type,
                $d->data->provider,
                $d->data->reference,
                $d->data->startOn?->format('Y-m-d'),
                $d->data->expiryOn?->format('Y-m-d'),
            ], $documents),
            'recurring documents keep their dates without Make schedules',
        );
        self::assertSame('FASTag', $documents[2]->data->title);
        $bikeDocs = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($enfield->id);
        self::assertSame(ComplianceType::Inspection, $bikeDocs[0]->data->type, 'roadworthiness is an inspection');

        $reminders = array_values(array_filter(
            $this->service($app, ReminderRepository::class)->listForVehicles([$swift->id]),
            static fn ($r): bool => $r->source === ReminderSource::Manual,
        ));
        usort($reminders, static fn ($a, $b): int => $a->title <=> $b->title);
        self::assertSame(
            [
                ['Custom: Wash and wax', '2026-03-01', ReminderStatus::Done],
                ['Maintenance: Annual service', '2026-12-15', ReminderStatus::Upcoming],
            ],
            array_map(static fn ($r): array => [$r->title, $r->dueOn?->format('Y-m-d'), $r->status], $reminders),
            'every reminder, the completed one done (#466)',
        );
        self::assertSame(7, $reminders[1]->leadTimeDays);

        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($swift->id);
        $manual = array_values(array_filter($readings, static fn ($r): bool => $r->source->value === 'manual'));
        self::assertCount(1, $manual);
        self::assertSame('46210', Decimal::trim($manual[0]->readingKm));

        $attachments = $this->service($app, AttachmentRepository::class)->listForVehicle($swift->id);
        $owners = array_map(static fn ($a): string => $a->ownerType->value, $attachments);
        sort($owners);
        self::assertSame(
            [AttachmentOwner::Compliance->value, AttachmentOwner::Fuel->value, AttachmentOwner::Maintenance->value],
            $owners,
        );
        $policy = array_values(array_filter($attachments, static fn ($a): bool => $a->ownerType === AttachmentOwner::Compliance));
        self::assertSame('policy.pdf', $policy[0]->filename, 'the name the owner gave it, not Tracktor\'s');
    }

    public function testImportingTheSameExportAgainAddsNothingAndANewerOneOnlyWhatIsNew(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(self::indian());
        $files = $this->uploads();
        $this->importAll($app, $owner, $importer, self::fixture('export-metric.json'), $files);
        $before = $this->counts($app, $owner);

        // Matched by Tracktor's ids (#469): the same vehicles, every row already imported.
        $again = $this->previewAll($app, $owner, $importer, self::fixture('export-metric.json'), $files);
        self::assertSame(0, $again->total(AppRowStatus::Import));
        $rows = 8 + 3 + 3 + 2 + 3 + 1 + 1 + 1;
        self::assertSame($rows + 2, $again->total(AppRowStatus::AlreadyImported), 'every row and both readings');
        foreach ($again->vehicles as $vehicle) {
            self::assertNotNull($vehicle->target);
            self::assertSame([], $vehicle->warnings);
        }

        // A newer export: one more fill-up and a higher odometer.
        /** @var array{data: array{fuelLogs: list<array<string, mixed>>, vehicles: list<array<string, mixed>>}} $json */
        $json = json_decode((string) file_get_contents(self::path('export-metric.json')), true);
        $json['data']['fuelLogs'][] = [
            'id' => '0f6a3d2e-1111-4222-8333-944455556666',
            'vehicleId' => $json['data']['vehicles'][0]['id'] ?? '',
            'date' => '2026-07-01T00:00:00.000Z',
            'odometer' => 46500,
            'fuelAmount' => 30.01,
            'rate' => 104.9,
            'cost' => 3148.05,
            'filled' => true,
            'missedLast' => false,
            'notes' => null,
            'attachment' => null,
        ];
        $json['data']['vehicles'][0]['odometer'] = 46800;
        $newer = TracktorReader::read('newer.json', (string) json_encode($json));
        $preview = $this->previewAll($app, $owner, $importer, $newer, $files);
        self::assertSame(2, $preview->total(AppRowStatus::Import), 'the new fill-up and the new reading');
        $importer->import($owner, $preview, $files);
        $files->close();

        $after = $this->counts($app, $owner);
        self::assertSame($before['fills'] + 1, $after['fills']);
        self::assertSame($before['vehicles'], $after['vehicles']);
    }

    public function testChoosingAnotherVehicleForImportedRowsWarnsAndNamesTheHolder(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(self::indian());
        $this->importAll($app, $owner, $importer, self::fixture('export-metric.json'), null);
        $export = self::fixture('export-metric.json');
        $manageable = $this->manageable($app, $owner);
        $guess = $importer->guess($owner, $export, $manageable);

        $options = TracktorImportOptions::fromQuery(['vehicle' => [$export->vehicles[0]->id => 'new']], $guess);
        $preview = $importer->analyse($owner, $export, $options, $this->targets($options, $manageable), $manageable);

        self::assertSame(
            [['key' => 'import_app.tracktor.warning.held', 'params' => ['name' => 'Maruti Suzuki Swift']]],
            $preview->vehicles[0]->warnings,
        );
        $fills = $preview->vehicles[0]->count(V::FILLS, AppRowStatus::Import);
        self::assertSame(8, $fills, 'a new vehicle would take them all again');
    }

    public function testTheImperialGarageReadsUkGallonsMilesAndKwh(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(null);
        $export = self::fixture('export-imperial.json');

        $options = $importer->guess($owner, $export, []);
        self::assertSame(DistanceUnit::Mile, $options->distanceUnit);
        self::assertSame(VolumeUnit::UkGallon, $options->volumeUnit, 'an owner in the UK: the UK gallon (#464)');

        $preview = $importer->analyse($owner, $export, $options, [], []);
        [$golf, $leaf, $bus] = $preview->vehicles;
        $sanity = $golf->sanity;
        self::assertNotNull($sanity);
        $mpg = ConsumptionUnit::MpgUk->fromDistanceAndVolume((float) $sanity->distanceKm, (float) $sanity->volume);
        self::assertEqualsWithDelta(45.0, (float) $mpg, 1.0, 'about 45 mpg, as typed into Tracktor');
        self::assertSame('GBP', $golf->currency);
        self::assertNull($golf->newVehicle?->currency, 'the owner\'s own currency');
        self::assertSame(FuelType::Electric, $leaf->newVehicle?->fuelType);
        self::assertSame(
            [['key' => 'import_app.tracktor.warning.type', 'params' => ['type' => 'bus']]],
            $bus->warnings,
            'a bus comes across as a car, highlighted (#467)',
        );
        self::assertSame(VehicleType::Car, $bus->newVehicle?->type);
        self::assertSame([], $bus->rows(V::READINGS), 'no odometer of its own');
        self::assertSame(0, $preview->filesLeftBehind(), 'no files named in this export');

        $usOptions = TracktorImportOptions::fromQuery(['volume_unit' => VolumeUnit::UsGallon->value], $options);
        $us = $importer->analyse($owner, $export, $usOptions, [], [])->vehicles[0]->sanity;
        self::assertNotNull($us);
        self::assertLessThan($sanity->per100Km(), $us->per100Km(), 'US gallons are smaller: the economy looks better');

        $done = $importer->import($owner, $preview);
        $leafWritten = $done->vehicles[1]->written;
        self::assertNotNull($leafWritten);
        $charges = $this->service($app, FuelEntryRepository::class)->listForVehicle($leafWritten->id);
        $kwh = array_map(static fn ($c): string => Decimal::trim($c->data->volume), $charges);
        self::assertSame(['30', '28.5', '29'], $kwh, 'kWh as they are');
        self::assertSame(Fuel::Electricity, $charges[0]->data->fuel);
        $golfFills = $this->service($app, FuelEntryRepository::class)->listForVehicle((int) $done->vehicles[0]->written?->id);
        self::assertSame('43.188', Decimal::trim($golfFills[0]->data->volume), '9.5 UK gallons in litres');
        self::assertSame('62.61', Decimal::trim($golfFills[0]->data->totalCost), 'the total as typed');
    }

    public function testATracktor1ExportBringsInsuranceAndPollutionAndTheFilesCurrency(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(null);
        $export = self::fixture('export-1.4.1.json');

        $options = $importer->guess($owner, $export, []);
        self::assertSame(['insurance' => 'insurance', 'emissions' => 'pollution'], $options->documents);
        $preview = $importer->analyse($owner, $export, $options, [], []);
        $octavia = $preview->vehicles[0];
        self::assertSame('EUR', $octavia->newVehicle?->currency, 'a new vehicle takes Tracktor\'s currency (#472)');
        self::assertSame('EUR', $octavia->currency);
        self::assertNotNull($octavia->newVehicle);
        self::assertSame(VehicleType::Car, $octavia->newVehicle->type, '1.x has no type');

        $done = $importer->import($owner, $preview);
        $vehicle = $done->vehicles[0]->written;
        self::assertNotNull($vehicle);
        self::assertSame('EUR', $vehicle->data->currency);
        $documents = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($vehicle->id);
        self::assertSame(
            [
                [ComplianceType::Insurance, 'HUK-Coburg', 'HUK-55512', '412.5'],
                [ComplianceType::Pollution, 'DEKRA Berlin', 'AU-2026-77', '0'],
            ],
            array_map(
                static fn ($d): array => [$d->data->type, $d->data->provider, $d->data->reference, Decimal::trim($d->data->cost)],
                $documents,
            ),
        );
    }

    public function testAnExistingVehicleInAnotherCurrencyIsWarnedAndNothingIsConverted(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(null);
        $golf = $this->service($app, VehicleService::class)->create(
            $owner,
            new VehicleData(VehicleType::Car, 'Maruti Suzuki', 'Swift', FuelType::Petrol, registration: 'KA 01 AB 1234'),
        );
        $export = self::fixture('export-metric.json');
        $manageable = $this->manageable($app, $owner);

        $options = $importer->guess($owner, $export, $manageable);
        self::assertSame((string) $golf->id, $options->vehicles[$export->vehicles[0]->id], 'matched by registration');
        $preview = $importer->analyse($owner, $export, $options, $this->targets($options, $manageable), $manageable);
        self::assertSame(
            [['key' => 'import_app.tracktor.warning.currency', 'params' => ['file' => 'INR', 'vehicle' => 'GBP']]],
            $preview->vehicles[0]->warnings,
        );
        $note = $preview->vehicles[0]->rows(V::FILES)[0]->note;
        self::assertSame('import_app.tracktor.reason.files_not_given', $note['key'] ?? null);
        self::assertSame(4, $preview->filesLeftBehind());
    }

    public function testMakeSchedulesTurnsRecurringServiceRemindersAndDocumentsIntoLogbooksOwn(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(self::indian());
        $export = self::fixture('export-metric.json');
        $options = TracktorImportOptions::fromQuery(
            ['vehicle' => [], 'schedules' => '1', 'reminders' => '1'],
            $importer->guess($owner, $export, []),
        );
        self::assertTrue($options->schedules);

        $preview = $importer->analyse($owner, $export, $options, [], []);
        $car = $preview->vehicles[0];
        self::assertSame(1, $car->count(V::SCHEDULES, AppRowStatus::Import));
        self::assertSame('import_app.tracktor.reason.became_schedule', $car->rows(V::REMINDERS)[0]->note['key'] ?? null);
        $nextDue = ['key' => 'import_app.tracktor.next_due', 'params' => ['date' => '2027-03-01']];
        self::assertSame($nextDue, $car->rows(V::DOCUMENTS)[0]->note);

        $done = $importer->import($owner, $preview);
        $swift = $done->vehicles[0]->written;
        self::assertNotNull($swift);
        $schedules = $this->service($app, MaintenanceScheduleRepository::class)->listForVehicle($swift->id);
        $made = array_map(static fn ($s): array => [$s->data->title, $s->data->intervalMonths], $schedules);
        self::assertSame([['Annual service', 12]], $made);
        $insurance = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($swift->id)[0];
        self::assertSame('2027-03-01', $insurance->data->expiryOn?->format('Y-m-d'), 'the next due date Tracktor shows');
    }

    public function testLpgByWeightIsLeftOutAndCngInPoundsBecomesKilograms(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(null);
        $export = TracktorReader::read('gas.json', (string) json_encode(['version' => '1.0', 'encrypted' => false, 'data' => [
            'vehicles' => [
                ['id' => 'lpg-car', 'make' => 'Dacia', 'model' => 'Sandero', 'fuelType' => 'lpg', 'vehicleType' => 'car'],
                ['id' => 'cng-car', 'make' => 'Fiat', 'model' => 'Panda', 'fuelType' => 'cng', 'vehicleType' => 'car'],
            ],
            'fuelLogs' => [
                ['id' => 'l1', 'vehicleId' => 'lpg-car', 'date' => '2026-05-01T00:00:00.000Z', 'odometer' => 1000,
                    'fuelAmount' => 20, 'rate' => 0.9, 'cost' => 18, 'filled' => true, 'missedLast' => false],
                ['id' => 'c1', 'vehicleId' => 'cng-car', 'date' => '2026-05-01T00:00:00.000Z', 'odometer' => 1000,
                    'fuelAmount' => 22.0462, 'rate' => 0.5, 'cost' => 11.02, 'filled' => true, 'missedLast' => false],
            ],
            'configs' => [
                ['key' => 'unitOfDistance', 'value' => 'kilometer'],
                ['key' => 'unitOfVolume', 'value' => 'liter'],
                ['key' => 'unitOfLpg', 'value' => 'kilogram'],
                ['key' => 'unitOfCng', 'value' => 'pound'],
            ],
        ]]));

        $preview = $importer->analyse($owner, $export, $importer->guess($owner, $export, []), [], []);
        [$lpg, $cng] = $preview->vehicles;
        self::assertSame(AppRowStatus::NotImported, $lpg->rows(V::FILLS)[0]->status);
        self::assertSame('import_app.tracktor.reason.unit', $lpg->rows(V::FILLS)[0]->note['key'] ?? null);
        self::assertSame(AppRowStatus::Import, $cng->rows(V::FILLS)[0]->status);
        $data = $cng->rows(V::FILLS)[0]->data;
        self::assertInstanceOf(FuelEntryData::class, $data);
        self::assertSame('10', Decimal::trim(Decimal::round($data->volume, 2)), '22.0462 lb is 10 kg');
        self::assertSame(Fuel::Cng, $data->fuel);
    }

    public function testAFailureWritesNothingAndRemovesTheFilesStoredOnTheWay(): void
    {
        [$app, $owner, $importer] = $this->setUpImport(self::indian());
        $existing = $this->service($app, VehicleService::class)
            ->create($owner, new VehicleData(VehicleType::Car, 'Maruti Suzuki', 'Swift', FuelType::Petrol));
        $export = self::fixture('export-metric.json');
        $files = $this->uploads();
        $manageable = $this->manageable($app, $owner);
        $options = TracktorImportOptions::fromQuery(['vehicle' => [
            $export->vehicles[0]->id => (string) $existing->id,
            $export->vehicles[1]->id => 'skip',
        ]], $importer->guess($owner, $export, $manageable));
        $preview = $importer->analyse($owner, $export, $options, $this->targets($options, $manageable), $manageable, $files);
        // The same vehicle twice: the second one's source ids collide with the first's.
        $twice = $preview->withVehicles([$preview->vehicles[0], $preview->vehicles[0]]);

        try {
            $importer->import($owner, $twice, $files);
            self::fail('Expected the import to fail.');
        } catch (Throwable) {
        }
        $files->close();

        self::assertSame([], $this->service($app, FuelEntryRepository::class)->listForVehicle($existing->id));
        self::assertSame([], $this->service($app, AttachmentRepository::class)->listForVehicle($existing->id));
        self::assertSame([], self::filesIn($this->uploadDir()), 'the files stored on the way are deleted again');
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: User, 2: TracktorImporter}
     */
    private function setUpImport(?DisplayPreferences $preferences): array
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->pinClock($app, self::NOW);
        $owner = $this->createOwner($app, 'owner', $preferences);

        return [$app, $owner, $this->service($app, TracktorImporter::class)];
    }

    private static function indian(): DisplayPreferences
    {
        return new DisplayPreferences(
            'en_IN',
            'Asia/Kolkata',
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            ConsumptionUnit::KmPerLitre,
            'INR',
        );
    }

    private function uploads(): AppFiles
    {
        $root = sys_get_temp_dir() . '/logbook-tracktor-' . bin2hex(random_bytes(6));

        return (new ArchiveReader($root))->openUploads(self::path('uploads.zip'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function importAll(
        App $app,
        User $owner,
        TracktorImporter $importer,
        TracktorExport $export,
        ?AppFiles $files,
    ): TracktorPreview {
        return $importer->import($owner, $this->previewAll($app, $owner, $importer, $export, $files), $files);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function previewAll(
        App $app,
        User $owner,
        TracktorImporter $importer,
        TracktorExport $export,
        ?AppFiles $files,
    ): TracktorPreview {
        $manageable = $this->manageable($app, $owner);
        $options = $importer->guess($owner, $export, $manageable);

        return $importer->analyse($owner, $export, $options, $this->targets($options, $manageable), $manageable, $files);
    }

    /**
     * @param list<Vehicle> $manageable
     * @return array<string, Vehicle>
     */
    private function targets(TracktorImportOptions $options, array $manageable): array
    {
        $targets = [];
        foreach ($options->vehicles as $tracktorId => $target) {
            foreach ($manageable as $vehicle) {
                if ((string) $vehicle->id === $target) {
                    $targets[$tracktorId] = $vehicle;
                }
            }
        }

        return $targets;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{vehicles: int, fills: int}
     */
    private function counts(App $app, User $owner): array
    {
        $vehicles = $this->manageable($app, $owner);
        $fills = 0;
        foreach ($vehicles as $vehicle) {
            $fills += count($this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id));
        }

        return ['vehicles' => count($vehicles), 'fills' => $fills];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    private function manageable(App $app, User $owner): array
    {
        return $this->service($app, VehicleService::class)->listWith($owner, VehicleAbility::Manage);
    }

    private static function path(string $name): string
    {
        return Kernel::rootDir() . '/tests/Fixtures/import/tracktor/' . $name;
    }

    private static function fixture(string $name): TracktorExport
    {
        return TracktorReader::read($name, (string) file_get_contents(self::path($name)));
    }

    /**
     * @return list<string>
     */
    private static function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
