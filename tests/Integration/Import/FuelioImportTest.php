<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Import;

use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Kernel;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Import\App\AppImportOptions;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\AppVehiclePreview;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\Fuelio\FuelioExport;
use Logbook\Service\Import\App\Fuelio\FuelioImporter;
use Logbook\Service\Import\App\Fuelio\FuelioReader;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\FuelioCsv;
use Logbook\Tests\Unit\Service\Import\App\FuelioReaderTest;
use Psr\Container\ContainerInterface;
use Slim\App;
use Throwable;

/**
 * Importing from Fuelio (spec.md §7.13 *Importing from another app*, Phase
 * 31), through the importer service: the owner's own export completely,
 * economy per tank as Fuelio has it, photos from the backup, re-imports,
 * one transaction, and the mapping's choices.
 */
final class FuelioImportTest extends AppTestCase
{
    private const string NOW = '2026-10-04T09:00:00Z';

    public function testTheOwnersExportImportsCompletelyWithFuelioEconomyPerTank(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $export = FuelioReaderTest::fixture();
        $expected = FuelioReaderTest::expected();

        $options = $importer->guess($owner, $export, []);
        self::assertSame(AppImportOptions::NEW_VEHICLE, $options->vehicle);
        self::assertSame(DistanceUnit::Mile, $options->distanceUnit, 'from the Log header');
        self::assertSame([110 => 'petrol'], $options->fuels);
        self::assertSame('maintenance:service', $options->categories[1]);
        self::assertSame('expense:parking', $options->categories[5]);
        self::assertTrue($importer->unitsFromFile($owner, $export));

        $preview = $importer->analyse($owner, $export, $options, null);
        self::assertSame($expected['fills'], $preview->count(AppVehiclePreview::FILLS, AppRowStatus::Import));
        self::assertSame(1, $preview->count(AppVehiclePreview::COSTS, AppRowStatus::Import));
        self::assertSame(4, $preview->count(AppVehiclePreview::STATIONS, AppRowStatus::Import));
        self::assertSame(
            $expected['photos'],
            $preview->count(AppVehiclePreview::PHOTOS, AppRowStatus::NotImported),
            'photos come with the backup',
        );
        self::assertSame('import_app.reason.photos_web', $preview->rows(AppVehiclePreview::PHOTOS)[0]->note['key'] ?? null);

        $sanity = $preview->sanity;
        self::assertNotNull($sanity);
        self::assertSame(EnergyKind::Liquid, $sanity->kind);
        self::assertSame($expected['measured_tanks'], $sanity->tanks);
        self::assertSame(ConsumptionUnit::MpgUk, $sanity->appUnit, "Fuelio's mpg is the UK gallon's");
        self::assertSame([15, 15], [$sanity->compared, $sanity->agreeing]);
        self::assertTrue($sanity->matchesApp());
        self::assertFalse($sanity->isUnusual());
        self::assertSame(['Audi', 'RS3', 'AB12 CDE', FuelType::Petrol, '50.0', 'Test car'], [
            $preview->newVehicle?->make,
            $preview->newVehicle?->model,
            $preview->newVehicle?->registration,
            $preview->newVehicle?->fuelType,
            $preview->newVehicle?->capacity,
            $preview->newVehicle?->nickname,
        ]);
        self::assertSame([], $this->manageable($app, $owner), 'a preview writes nothing');

        [$done] = $importer->import($owner, [$preview]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);

        // Every tank's economy is Fuelio's own, to its rounding.
        $history = $this->service($app, FuelService::class)->history($vehicle);
        $measured = $history->measured(EnergyKind::Liquid);
        self::assertCount($expected['measured_tanks'], $measured);
        $byOpening = $expected['fuelio_mpg_by_opening_odometer'];
        foreach ($measured as $fill) {
            $segment = $fill->segment;
            self::assertNotNull($segment?->opening);
            $openingMiles = sprintf('%.1f', round((float) $segment->opening->data->odometerKm / DistanceUnit::KM_PER_MILE, 1));
            $mpg = ConsumptionUnit::MpgUk->fromDistanceAndVolume((float) $segment->distanceKm, (float) $segment->volume);
            $fuelio = (string) $byOpening[$openingMiles];
            $decimals = strlen((string) strrchr($fuelio, '.')) - 1;
            self::assertSame((float) $fuelio, round((float) $mpg, $decimals), 'tank from ' . $openingMiles . ' mi');
        }
        $summary = $history->summary(EnergyKind::Liquid);
        self::assertSame($expected['total_volume_litres'], number_format((float) $summary?->totalVolume, 2, '.', ''));
        self::assertSame($expected['total_cost'], number_format((float) $summary?->totalCost, 2, '.', ''));

        // Stations: the favourites with their positions, each a favourite; fill-ups linked to them.
        $stations = $this->service($app, StationService::class);
        self::assertCount(4, $stations->favouriteIds($owner));
        $stationA = $this->service($app, StationRepository::class)->findByName('Station A');
        self::assertNotNull($stationA);
        self::assertTrue($stationA->data->hasPosition());
        self::assertSame('GB', $stationA->data->country);
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
        $newest = $fills[count($fills) - 1];
        self::assertSame($stationA->id, $newest->data->stationId);
        self::assertSame('Station A', $newest->data->station);

        // The service: Maintenance, with its reading.
        $services = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(1, $services);
        self::assertSame([MaintenanceCategory::Service, '1st Oil Service', '220.000'], [
            $services[0]->data->category, $services[0]->data->title, $services[0]->data->cost,
        ]);
        self::assertEqualsWithDelta(3469.746, (float) $services[0]->data->odometerKm, 0.001, '2,156 mi');
        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(19, $readings, 'each fill-up and the service wrote a reading');
        self::assertSame($owner->id, $fills[0]->createdBy);
        self::assertSame($expected['economy_check_flags'], $done->unusualFills, 'the two early, thirstier tanks stand out');
    }

    public function testImportingTheSameExportAgainFindsEverythingAlreadyImportedEvenWhenEdited(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $export = FuelioReaderTest::fixture();
        [$done] = $importer->import($owner, [$importer->analyse($owner, $export, $importer->guess($owner, $export, []), null)]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);

        // Edit an imported fill-up: its duplicate key changes, its origin doesn't.
        $fuel = $this->service($app, FuelService::class);
        $entry = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id)[3];
        $data = $entry->data;
        $fuel->update($vehicle, $entry, new \Logbook\Domain\Fuel\FuelEntryData(
            $data->filledAt->modify('+1 hour'),
            $data->odometerKm,
            $data->fuel,
            $data->volume,
            $data->pricePerUnit,
            $data->totalCost,
            $data->isPartial,
            $data->isMissedPrevious,
            $data->station,
            'Edited after the import',
        ));

        $manageable = $this->manageable($app, $owner);
        $again = $importer->guess($owner, $export, $manageable);
        self::assertSame((string) $vehicle->id, $again->vehicle, 'the vehicle holding these rows is proposed');
        $preview = $importer->analyse($owner, $export, $again, $vehicle);
        self::assertSame(18, $preview->count(AppVehiclePreview::FILLS, AppRowStatus::AlreadyImported));
        self::assertSame(1, $preview->count(AppVehiclePreview::COSTS, AppRowStatus::AlreadyImported));
        self::assertSame(4, $preview->count(AppVehiclePreview::STATIONS, AppRowStatus::AlreadyImported));
        self::assertSame(0, $preview->total(AppRowStatus::Import));
    }

    public function testANewerExportAddsOnlyTheNewRows(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $text = (string) file_get_contents(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/export.csv');
        // A month earlier, the export ended three fill-ups sooner (Fuelio lists the newest first).
        $lines = explode("\n", $text);
        $older = implode("\n", [...array_slice($lines, 0, 5), ...array_slice($lines, 8)]);

        $first = self::export($older);
        self::assertCount(15, $first->fills);
        [$done] = $importer->import($owner, [$importer->analyse($owner, $first, $importer->guess($owner, $first, []), null)]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);

        $newer = self::export($text);
        $options = $importer->guess($owner, $newer, $this->manageable($app, $owner));
        $preview = $importer->analyse($owner, $newer, $options, $vehicle);
        self::assertSame(3, $preview->count(AppVehiclePreview::FILLS, AppRowStatus::Import));
        self::assertSame(15, $preview->count(AppVehiclePreview::FILLS, AppRowStatus::AlreadyImported));
        $importer->import($owner, [$preview]);
        self::assertCount(18, $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id));
    }

    public function testTheBackupBringsEachPhotoToItsFillUpWithoutItsExif(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $root = sys_get_temp_dir() . '/logbook-fuelio-' . bin2hex(random_bytes(6));
        $archive = (new ArchiveReader($root))->open(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/backup.fuelio.zip');
        $export = self::export($archive->csv[0]['contents']);

        $preview = $importer->analyse($owner, $export, $importer->guess($owner, $export, []), null, $archive);
        self::assertSame(45, $preview->count(AppVehiclePreview::PHOTOS, AppRowStatus::Import));
        [$done] = $importer->import($owner, [$preview], $archive);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);
        $archive->close();
        ArchiveReader::removeDirectory($root);

        $attachments = $this->service($app, AttachmentRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(45, $attachments);
        $byFill = [];
        foreach ($attachments as $attachment) {
            self::assertSame(AttachmentOwner::Fuel, $attachment->ownerType);
            $byFill[$attachment->ownerId] = ($byFill[$attachment->ownerId] ?? 0) + 1;
        }
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
        $newest = $fills[count($fills) - 1];
        self::assertSame(FuelioReaderTest::expected()['photos_by_fill_unique_id'][18] ?? null, $byFill[$newest->id] ?? 0);

        $stored = $this->uploadDir() . '/' . $attachments[0]->storedPath;
        self::assertFileExists($stored);
        $exif = @exif_read_data($stored);
        self::assertFalse(is_array($exif) && isset($exif['Make']), 'the photo is stored without its EXIF');
        self::assertDirectoryDoesNotExist($root);
    }

    public function testMilesReadAsKilometresDisagreeWithFuelio(): void
    {
        [, $owner, $importer] = $this->setUpImport();
        $export = FuelioReaderTest::fixture();
        $guess = $importer->guess($owner, $export, []);
        $wrong = AppImportOptions::fromQuery(['vehicle' => 'new', 'distance_unit' => 'km'], $guess);

        $sanity = $importer->analyse($owner, $export, $wrong, null)->sanity;
        self::assertNotNull($sanity);
        self::assertFalse($sanity->matchesApp());
        self::assertSame(0, $sanity->agreeing);
    }

    public function testAFailureInTheSecondVehicleWritesNothingForTheFirst(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $octavia = new VehicleData(VehicleType::Car, 'Skoda', 'Octavia', FuelType::Petrol);
        $target = $this->service($app, VehicleService::class)->create($owner, $octavia);
        $root = sys_get_temp_dir() . '/logbook-fuelio-' . bin2hex(random_bytes(6));
        $archive = (new ArchiveReader($root))->open(Kernel::rootDir() . '/tests/Fixtures/import/fuelio/backup.fuelio.zip');
        $export = self::export($archive->csv[0]['contents']);
        $options = AppImportOptions::fromQuery(['vehicle' => (string) $target->id], $importer->guess($owner, $export, []));

        // The same rows twice into one vehicle, as a double submit would: the
        // second copy's origins clash, after the first has written its photos.
        $first = $importer->analyse($owner, $export, $options, $target, $archive);
        $second = $importer->analyse($owner, $export, $options, $target, $archive);
        try {
            $importer->import($owner, [$first, $second], $archive);
            self::fail('expected the second copy to fail');
        } catch (Throwable) {
        }
        $archive->close();

        self::assertSame([], $this->service($app, FuelEntryRepository::class)->listForVehicle($target->id));
        self::assertSame([], $this->service($app, AttachmentRepository::class)->listForVehicle($target->id));
        self::assertNull($this->service($app, StationRepository::class)->findByName('Station A'));
        self::assertSame([], self::files($this->uploadDir()), 'the photos stored on the way are deleted again');
    }

    public function testTheMappingRoutesCategoriesAndFuelsAndSkipsWhatItShould(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $csv = (new FuelioCsv('km', 'litres', 'l/100km'))
            ->vehicle(['ImportCSVDateFormat' => 'dd.MM.yyyy', 'TankCount' => '2', 'Tank2Type' => '400'])
            ->category(50, 'Tyres')
            ->category(51, 'Stuff')
            ->fill('02.01.2026 08:00', '1000', '40,5', '60,75', '1,5')
            ->fill('03.01.2026 08:00', '1100', '12,4', '16,12', '1,3', ['FuelType' => '400', 'TankNumber' => '2'])
            ->fill('10.01.2026 08:00', '1600', '35', '52,5', '1,5')
            ->fill('11.01.2026 08:00', '1650', '20', '6', '0,3', ['FuelType' => '500'])
            ->fill('12.01.2026 08:00', '1700', '30', '7,5', '0,25', ['FuelType' => '600', 'TankNumber' => '2'])
            ->cost('Car park', '05.01.2026', '0', 5, '4.5')
            ->cost('Tax refund', '06.01.2026', '0', 4, '30', ['isIncome' => '1'])
            ->cost('Template', '06.01.2026', '0', 1, '99', ['isTemplate' => '1'])
            ->cost('Winter tyres', '07.01.2026', '1500', 50, '400')
            ->cost('Odds and ends', '07.01.2026', '0', 51, '12')
            ->cost('Car wash', '08.01.2026', '0', 6, '8')
            ->build();
        $export = self::export($csv);
        $guess = $importer->guess($owner, $export, []);
        self::assertSame('maintenance:tyres', $guess->categories[50], 'a user category by its name');
        self::assertSame('maintenance:other', $guess->categories[51]);
        self::assertFalse(FuelioImporter::knownCategory(51), 'highlighted on the mapping page');
        self::assertSame(['petrol', 'petrol', 'petrol', 'petrol'], array_values($guess->fuels), 'unknown codes: the usual fuel');
        self::assertFalse(FuelioImporter::knownFuelCode(400));

        $options = AppImportOptions::fromQuery([
            'vehicle' => 'new',
            'cat' => ['6' => 'skip'],
            'fuel' => ['400' => 'cng', '500' => 'skip', '600' => 'ev'],
        ], $guess);
        self::assertSame(\Logbook\Service\Import\DateOrder::DayFirst, $options->dateOrder, 'from the export\'s date format');
        $preview = $importer->analyse($owner, $export, $options, null);
        $reasons = static fn (string $section): array => array_map(
            static fn ($row): ?string => $row->note['key'] ?? null,
            $preview->withStatus($section, AppRowStatus::NotImported),
        );
        self::assertSame(['import_app.reason.fuel_skipped'], $reasons(AppVehiclePreview::FILLS));
        self::assertSame(
            ['import_app.reason.income', 'import_app.reason.template', 'import_app.reason.category_skipped'],
            $reasons(AppVehiclePreview::COSTS),
        );
        self::assertSame(FuelType::Cng, $preview->newVehicle?->fuelType, 'a bi-fuel CNG car');

        [$done] = $importer->import($owner, [$preview]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
        self::assertCount(4, $fills);
        $first = $fills[0]->data;
        self::assertSame(
            [40.5, 60.75, 1.5],
            [(float) $first->volume, (float) $first->totalCost, (float) $first->pricePerUnit],
            'decimal commas',
        );
        $local = $first->filledAt->setTimezone(new \DateTimeZone('Europe/London'));
        self::assertSame('2026-01-02 08:00', $local->format('Y-m-d H:i'), 'day first');
        self::assertSame([Fuel::Cng, 12.4], [$fills[1]->data->fuel, (float) $fills[1]->data->volume], 'kg as they are');
        $charge = $fills[3]->data;
        self::assertSame(
            [Fuel::Electricity, 30.0, 0.25],
            [$charge->fuel, (float) $charge->volume, (float) $charge->pricePerUnit],
            'kWh too',
        );

        $expenses = $this->service($app, ExpenseEntryRepository::class)->listForVehicle($vehicle->id);
        self::assertSame([ExpenseCategory::Parking], array_map(static fn ($e) => $e->data->category, $expenses));
        $maintenance = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($vehicle->id);
        self::assertEqualsCanonicalizing(
            [MaintenanceCategory::Tyres, MaintenanceCategory::Other],
            array_map(static fn ($e) => $e->data->category, $maintenance),
        );
    }

    public function testStationsAreMatchedByNameOrWithin150mAndFillUpPositionsAreNeverStored(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $stations = $this->service($app, StationService::class);
        $existing = $stations->create($owner, new StationData('Station A', latitude: '50.100000', longitude: '-3.400000'));
        $tesco = $stations->create($owner, new StationData('Tesco Antrim', latitude: '54.700000', longitude: '-6.200000'));

        $csv = (new FuelioCsv())
            ->station('Station A', '50.100000', '-3.400000', '77')
            ->fill('2026-01-02 08:00', '1000', '40', '60', '1.5', ['StationID' => '77', 'City' => 'Station A - Somewhere'])
            // 100 m north of Tesco, under another name in Fuelio.
            ->fill('2026-01-09 08:00', '1500', '35', '52.5', '1.5', [
                'City' => 'Tesco Extra - Antrim', 'latitude' => '54.700900', 'longitude' => '-6.200000',
            ])
            // Nowhere near anything known: a new station, without a position.
            ->fill('2026-01-16 08:00', '2000', '35', '52.5', '1.5', [
                'City' => 'Corner Garage - Lisburn', 'latitude' => '54.500000', 'longitude' => '-6.000000',
            ])
            ->build();
        $export = self::export($csv);
        $preview = $importer->analyse($owner, $export, $importer->guess($owner, $export, []), null);
        self::assertSame('stations.import.links', $preview->rows(AppVehiclePreview::STATIONS)[0]->note['key'] ?? null);
        [$done] = $importer->import($owner, [$preview]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);

        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($vehicle->id);
        self::assertSame($existing->id, $fills[0]->data->stationId, 'by Fuelio id, through the favourite');
        self::assertSame($tesco->id, $fills[1]->data->stationId, 'within 150 m');
        $corner = $this->service($app, StationRepository::class)->findByName('Corner Garage');
        self::assertNotNull($corner);
        self::assertSame($corner->id, $fills[2]->data->stationId);
        self::assertFalse($corner->data->hasPosition(), "a fill-up's position is never stored");
        self::assertSame([$existing->id], $stations->favouriteIds($owner));
    }

    public function testRepeatingCostsBecomeSchedulesWhenAsked(): void
    {
        [$app, $owner, $importer] = $this->setUpImport();
        $csv = (new FuelioCsv())
            ->fill('2026-01-02 08:00', '1000', '40', '60', '1.5')
            ->cost('Oil change', '2025-01-10', '5000', 1, '90', ['RepeatOdo' => '10000', 'RepeatMonths' => '12'])
            ->cost('Oil change', '2026-01-12', '15000', 1, '95', ['RepeatOdo' => '10000', 'RepeatMonths' => '12'])
            ->build();
        $export = self::export($csv);
        $options = AppImportOptions::fromQuery(['vehicle' => 'new', 'schedules' => '1'], $importer->guess($owner, $export, []));
        $preview = $importer->analyse($owner, $export, $options, null);
        self::assertSame(1, $preview->count(AppVehiclePreview::SCHEDULES, AppRowStatus::Import));
        [$done] = $importer->import($owner, [$preview]);
        $vehicle = $done->written;
        self::assertNotNull($vehicle);

        $schedules = $this->service($app, ScheduleService::class)->list($vehicle);
        self::assertCount(1, $schedules);
        $schedule = $schedules[0]->data;
        self::assertSame(['Oil change', '10000.000', 12], [$schedule->title, $schedule->intervalKm, $schedule->intervalMonths]);
        self::assertSame('2026-01-12', $schedules[0]->lastDone->on?->format('Y-m-d'), 'last done from the latest record');
        foreach ($this->service($app, MaintenanceEntryRepository::class)->listForVehicle($vehicle->id) as $entry) {
            self::assertSame($schedules[0]->id, $entry->data->scheduleId);
        }

        // Without the option, no schedule.
        $without = AppImportOptions::fromQuery(['vehicle' => 'new'], $importer->guess($owner, $export, []));
        $plain = $importer->analyse($owner, $export, $without, null);
        self::assertSame([], $plain->rows(AppVehiclePreview::SCHEDULES));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: User, 2: FuelioImporter}
     */
    private function setUpImport(): array
    {
        $app = $this->createApp();
        $this->resetDatabase($app);
        $this->pinClock($app, self::NOW);
        $owner = $this->createOwner($app);
        $importer = $this->service($app, FuelioImporter::class);

        return [$app, $owner, $importer];
    }

    /**
     * @param App<ContainerInterface> $app
     * @return list<Vehicle>
     */
    private function manageable(App $app, User $owner): array
    {
        return $this->service($app, VehicleService::class)->listWith($owner, \Logbook\Domain\Access\VehicleAbility::Manage);
    }

    private static function export(string $csv): FuelioExport
    {
        $file = SectionSplitter::split('export.csv', $csv);
        self::assertNotNull($file);
        self::assertTrue(FuelioReader::detect($file));

        return FuelioReader::read($file);
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
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
