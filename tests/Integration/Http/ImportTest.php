<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Domain\Part\PartData;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Domain\Station\StationData;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\StationRepository;
use Logbook\Repository\TripRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartRemoval;
use Logbook\Service\Part\PartService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Config\AppSettings;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\UploadedFile;

/**
 * CSV import (spec.md §7.13): upload, map, preview, import. Rows are read
 * like the forms read them, bad rows are reported with their line, and
 * nothing is imported twice — including odometer readings that imported
 * fill-ups and services already wrote.
 */
final class ImportTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function testAFuelExportImportsBackExactlyInImperialUnits(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        // The owner uses miles and UK gallons, so every quantity is converted both ways.
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->fillUp(
            $app,
            $golf,
            '2026-03-31T23:30:00Z',
            '48280.320',
            '45.678',
            '66.64',
            pricePerLitre: '1.459000',
            grade: FuelGrade::E10_95,
        );
        $this->fillUp($app, $golf, '2026-04-20T17:05:00Z', '48885.123', '40.001', '0', partial: true, pricePerLitre: '0.000000');
        $this->service($app, FuelService::class)->create($golf, new FuelEntryData(
            new DateTimeImmutable('2026-05-02T08:15:00Z', new DateTimeZone('UTC')),
            '49400.000',
            Fuel::Petrol,
            '38.250',
            '1.529000',
            '58.48',
            false,
            true,
            'Tesco, Leeds',
            "Receipt \"lost\"\nsecond line",
        ));
        $csv = self::body($browser->get('/vehicles/' . $golf->id . '/export/fuel.csv'));

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'fuel', $csv, 'golf-fuel.csv');
        $mapping = self::body($browser->get($map));
        self::assertStringContainsString('golf-fuel.csv: 3 rows', $mapping);
        // Matched by the export's own headers, with the file's units read
        // from them; the grade from its code column (4), not its label (3).
        $guess = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        self::assertSame(
            ['0', '1', '2', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13'],
            [$guess['map_filled_at'], $guess['map_odometer'], $guess['map_fuel'], $guess['map_grade'], $guess['map_volume'],
                $guess['map_unit'], $guess['map_price'], $guess['map_total'], $guess['map_currency'], $guess['map_partial'],
                $guess['map_missed_previous'], $guess['map_station'], $guess['map_notes']],
        );
        self::assertSame('mi', $guess['distance_unit']);
        self::assertSame('Europe/London', $guess['zone']);
        self::assertSame('iso', $guess['date_order']);

        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Import 3 rows', $preview);
        $fills = $this->service($app, FuelEntryRepository::class);
        self::assertSame([], $fills->listForVehicle($polo->id), 'a preview writes nothing');

        $result = $this->commit($browser, $map, $preview);
        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('3 rows were imported.', self::body($result));

        $original = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        $imported = $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id);
        self::assertCount(3, $imported);
        foreach ($original as $i => $entry) {
            self::assertEquals($entry->data, $imported[$i]->data, 'fill-up ' . $i);
        }
        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($polo->id);
        self::assertCount(3, $readings, 'each fill-up wrote its reading');

        // The same file again: every row is already there.
        $again = $this->upload($browser, $polo->id, 'fuel', $csv);
        $preview = $this->preview($browser, $again);
        self::assertStringContainsString('Nothing to import', $preview);
        self::assertSame(3, substr_count($preview, 'Already there'));
        $response = $this->commit($browser, $again, $preview);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('None of the rows can be imported.', self::body($response));
        self::assertCount(3, $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id));
    }

    /**
     * K9 and K21: a derived amount too large for its column is a row error
     * (it was a 500), a time stored with seconds matches its exported minute,
     * and gallons are rounded to litres once, as the form rounds them.
     */
    public function testFillUpEdgesAreReadAsTheFormReadsThem(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');

        $csv = implode("\n", [
            'Date,Odometer,Fuel,Volume,Price,Total',
            '2026-06-01 09:00,1000,Petrol,0.001,,99999999999.999',
            '2026-06-08 09:00,1300,Petrol,,0.000001,99999999999.999',
            '2026-06-15 09:00,1600,Petrol,999999,999999,',
            '2026-06-22 09:00,1900,Petrol,40,1.459,',
        ]) . "\n";
        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map, ['volume_unit' => 'l']);
        self::assertStringContainsString('Import 1 row', $preview);
        self::assertSame(3, substr_count($preview, 'Worked out from the other two, this number is too large'));
        $response = $this->commit($browser, $map, $preview, ['skip_invalid' => '1']);
        self::assertSame(200, $response->getStatusCode(), self::body($response));
        self::assertCount(1, $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));

        $gallons = "Date,Odometer,Fuel,Volume,Price\n2026-06-29 09:00,2200,Petrol,11.087,6.63\n";
        $map = $this->upload($browser, $golf->id, 'fuel', $gallons);
        $this->commit($browser, $map, $this->preview($browser, $map, ['volume_unit' => 'gal_uk']));
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(2, $fills);
        // 11.087 UK gal × 4.54609 = 50.40249983 L: once rounded 50.402 (twice, via 50.402500, it was 50.403).
        self::assertSame('50.402', $fills[1]->data->volume);

        // Seconds, as demo data and the API store them: the export writes 09:15.
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $this->service($app, FuelService::class)->create($polo, new FuelEntryData(
            new DateTimeImmutable('2026-05-02T08:15:37Z', new DateTimeZone('UTC')),
            '49400.000',
            Fuel::Petrol,
            '38.250',
            '1.529000',
            '58.48',
            false,
            false,
            null,
            null,
        ));
        $export = self::body($browser->get('/vehicles/' . $polo->id . '/export/fuel.csv'));
        $again = $this->upload($browser, $polo->id, 'fuel', $export);
        $preview = $this->preview($browser, $again);
        self::assertStringContainsString('Nothing to import', $preview);
        self::assertSame(1, substr_count($preview, 'Already there'));
    }

    /**
     * K129: with MAX_UPLOAD_MB raised past what `memory_limit` can read, a
     * CSV within the upload limit is refused with a message, not a 500, and
     * its staged copy is removed.
     */
    public function testACsvTooLargeForTheMemoryLimitIsRefusedWithAMessage(): void
    {
        $app = $this->createApp(['MAX_UPLOAD_MB' => '100']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $csv = "Date,Litres,Price,Notes\n" . str_repeat('2026-06-01 09:00,40,1.60,' . str_repeat('x', 10_000) . "\n", 4000);
        $staged = $this->service($app, AppSettings::class)->cacheDir . '/staged/import-*';
        $before = glob($staged) ?: [];
        $limit = (string) ini_get('memory_limit');
        // 24 MB left, so reading may add 12 MB: well under the 40 MB these 4,000 rows take.
        ini_set('memory_limit', (string) (memory_get_usage(true) + 24 * 1024 * 1024));
        try {
            $response = $browser->post('/vehicles/' . $golf->id . '/import/fuel', [], ['file' => $this->file($csv, 'big.csv')]);
        } finally {
            ini_set('memory_limit', $limit);
        }

        self::assertGreaterThan(10 * 1024 * 1024, strlen($csv), 'over the default MAX_UPLOAD_MB');
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'The file is too large to read with the memory this server gives PHP.',
            self::body($response),
        );
        self::assertSame($before, glob($staged) ?: [], 'nothing is left staged');
    }

    /**
     * K61 and K21: a binary file is refused as not a CSV (its bytes were
     * listed as columns), and the size message names the limit.
     */
    public function testAnUploadThatIsNotACsvOrTooLargeIsRefusedClearly(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/import/fuel';

        $zip = "PK\x03\x04\x14\x00\x00\x00\x08\x00z(e.g. x\xFF\xE8\xE8\xF6\x8E\x85";
        $binaries = [$zip => 'backup.fuelio.zip', "%PDF-1.7\n" => 'receipt.csv', "Date,Odo\x00\n2026,1\n" => 'odd.csv'];
        foreach ($binaries as $bytes => $name) {
            $response = $browser->post($base, [], ['file' => $this->file($bytes, $name)]);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringContainsString('This isn’t a CSV file.', self::body($response), $name);
        }
        // UTF-16 text (with its byte-order mark) still reads.
        $utf16 = "\xFF\xFE" . mb_convert_encoding("Date,Litres,Price\n2026-06-01 09:00,40,1.60\n", 'UTF-16LE', 'UTF-8');
        self::assertSame(303, $browser->post($base, [], ['file' => $this->file($utf16, 'excel.csv')])->getStatusCode());

        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-');
        $this->tempFiles[] = $path;
        $tooLarge = new UploadedFile($path, 'big.csv', 'text/csv', 0, UPLOAD_ERR_INI_SIZE);
        $response = $browser->post($base, [], ['file' => $tooLarge]);
        self::assertSame(422, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringNotContainsString('{max}', $html);
        self::assertMatchesRegularExpression('/The file is too large \(maximum \d+ MB\)/', $html);
    }

    public function testTheGradeColumnTakesCodesLabelsAndShortLabels(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $csv = implode("\n", [
            'Date,Odometer,Fuel,Grade,Litres,Price,Total',
            '2026-06-01 09:00,1000,Petrol,e5_97,40,1.60,',
            '2026-06-08 09:00,1300,Petrol,E10 unleaded 95 RON,30,1.45,',
            '2026-06-15 09:00,1600,Petrol,E10,30,1.45,',
            '2026-06-22 09:00,1900,Petrol,,30,1.45,',
            '2026-06-29 09:00,2200,,E85,30,1.10,',
            '2026-07-06 09:00,2500,Petrol,B7,30,1.45,',
            '2026-07-13 09:00,2800,Petrol,Unleaded,30,1.45,',
            '2026-07-20 09:00,3100,Petrol,Super,30,1.45,',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Import 5 rows', $preview);
        self::assertStringContainsString('B7 is a diesel grade; this fill-up is petrol.', $preview);
        self::assertStringContainsString('“Unleaded” is not a fuel grade Logbook knows', $preview, 'too vague to be a grade');
        self::assertStringContainsString('“Super” is not a fuel grade Logbook knows', $preview);

        $this->commit($browser, $map, $preview, ['skip_invalid' => '1']);
        $grades = array_map(
            static fn ($e): ?string => $e->data->grade?->value,
            $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id),
        );
        self::assertSame(['e5_97', 'e10_95', 'e10_95', null, 'e85'], $grades, 'code, label, short label, blank, no fuel column');

        // A file without a grade column imports as before: not recorded.
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'fuel', "Date,Odometer,Litres,Price\n2026-06-01 09:00,1000,40,1.60\n");
        $this->commit($browser, $map, $this->preview($browser, $map));
        $imported = $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id);
        self::assertCount(1, $imported);
        self::assertNull($imported[0]->data->grade);
    }

    public function testTheStationColumnLinksOrCreatesStationsAndThePreviewSaysWhich(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $stations = $this->service($app, StationRepository::class);
        $at = new DateTimeImmutable('2026-06-01T00:00:00Z');
        $tesco = $stations->insert(new StationData('Tesco Antrim'), $this->owner($app)->id, $at);
        $csv = implode("\n", [
            'Date,Odometer,Litres,Price,Station',
            '2026-06-01 09:00,1000,40,1.60,tesco  ANTRIM',
            '2026-06-08 09:00,1300,30,1.45,Maxol Ballymena',
            '2026-06-15 09:00,1600,30,1.45,',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('station: Tesco Antrim', $preview);
        self::assertStringContainsString('new station: Maxol Ballymena', $preview);

        $this->commit($browser, $map, $preview);
        $links = array_map(
            static fn ($e): ?int => $e->data->stationId,
            $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id),
        );
        $maxol = $stations->findByName('maxol ballymena');
        self::assertNotNull($maxol);
        self::assertSame([$tesco, $maxol->id, null], $links);
    }

    public function testAStationNamedOnManyRowsIsCreatedOnceAndEveryFillUpWritesItsReading(): void
    {
        // The commit links each name once and inserts readings straight away (K49): the same result as row by row.
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $csv = implode("\n", [
            'Date,Odometer,Litres,Price,Station',
            '2026-06-01 09:00,1000,40,1.60,Maxol Ballymena',
            '2026-06-08 09:00,1300,30,1.45,maxol  BALLYMENA',
            '2026-06-15 09:00,1600,30,1.45,Maxol Ballymena',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        self::assertSame(200, $this->commit($browser, $map, $this->preview($browser, $map))->getStatusCode());

        $maxol = $this->service($app, StationRepository::class)->findByName('maxol ballymena');
        self::assertNotNull($maxol);
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(3, $fills);
        foreach ($fills as $fill) {
            self::assertSame($maxol->id, $fill->data->stationId);
            self::assertSame('Maxol Ballymena', $fill->data->station);
        }
        $readings = array_map(
            static fn ($r): array => [$r->source, $r->readingKm],
            $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id),
        );
        // The owner reads miles; the readings are stored in km.
        self::assertEqualsCanonicalizing([
            [OdometerSource::Fuel, '1609.344'],
            [OdometerSource::Fuel, '2092.147'],
            [OdometerSource::Fuel, '2574.950'],
        ], $readings);
    }

    public function testImportedFillUpsThatLookUnusualAreCounted(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        // 300 mi on 30 L a tank, then one on 60 L (the sixth checkable
        // segment: the first one the economy check can judge).
        $rows = ['Date,Odometer,Fuel,Litres,Price,Total'];
        foreach ([30, 30, 30, 30, 30, 30, 60, 30] as $i => $litres) {
            $rows[] = sprintf('2026-06-%02d 09:00,%d,Petrol,%d,1.50,', $i + 1, 1000 + 300 * $i, $litres);
        }

        $map = $this->upload($browser, $golf->id, 'fuel', implode("\n", $rows) . "\n");
        $html = self::body($this->commit($browser, $map, $this->preview($browser, $map)));
        self::assertStringContainsString('8 rows were imported.', $html);
        self::assertStringContainsString('1 imported fill-up looks unusual: check it on the Fuel tab.', $html);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '/fuel?check=1"', $html);

        // Nothing to say when every tank is ordinary.
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'fuel', str_replace(',60,', ',30,', implode("\n", $rows)) . "\n");
        $html = self::body($this->commit($browser, $map, $this->preview($browser, $map)));
        self::assertStringContainsString('8 rows were imported.', $html);
        self::assertStringNotContainsString('look unusual', $html);
        self::assertStringNotContainsString('looks unusual', $html);
    }

    public function testOdometerReadingsFromFillUpsAndServicesAreNeverImportedTwice(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->fillUp($app, $golf, '2026-06-01T09:00:00Z', '1000', '40', '60');
        $this->fillUp($app, $golf, '2026-07-01T09:00:00Z', '1600', '38', '57');
        $this->reading($app, $golf, '1300', '2026-06-15T18:00:00Z');
        $this->maintenance($app, $golf, '2026-08-01', 'Oil change', '80', '2000');
        $fuelCsv = self::body($browser->get('/vehicles/' . $golf->id . '/export/fuel.csv'));
        $odometerCsv = self::body($browser->get('/vehicles/' . $golf->id . '/export/odometer.csv'));

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'fuel', $fuelCsv);
        $this->commit($browser, $map, $this->preview($browser, $map));
        self::assertCount(2, $this->service($app, OdometerReadingRepository::class)->listForVehicle($polo->id));

        $map = $this->upload($browser, $polo->id, 'odometer', $odometerCsv);
        $preview = $this->preview($browser, $map);
        self::assertSame(3, substr_count($preview, 'Comes from an entry'), 'two fill-ups and a service');
        self::assertStringContainsString('Import 1 row', $preview);
        $this->commit($browser, $map, $preview);

        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($polo->id);
        self::assertSame(['1000.000', '1300.000', '1600.000'], array_map(static fn ($r): string => $r->readingKm, $readings));
        self::assertSame(OdometerSource::Manual, $readings[1]->source);

        // Another app's file has no source column: a reading a fill-up already
        // wrote is recognised by its time and distance (miles here).
        $other = "Date,Mileage\n2026-06-01 10:00,621.371192\n2026-09-01 12:00,1500\n";
        $map = $this->upload($browser, $polo->id, 'odometer', $other);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Already there', $preview);
        self::assertStringContainsString('Import 1 row', $preview);
    }

    /**
     * Modifications and parts are not imported (spec.md §7.13), so the
     * readings they wrote come back as ordinary ones rather than *implied*.
     */
    public function testReadingsFromModificationsAndPartsImportAsOrdinaryReadings(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $zone = new DateTimeZone('UTC');
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $parts = $this->service($app, PartService::class);
        $mods = $this->service($app, ModificationService::class);
        $on = new DateTimeImmutable('2026-02-01');
        $mirror = $parts->create($golf, new PartData('Mirror cap'), $zone, fitOn: $on, fitKm: '1000.000');
        $off = new DateTimeImmutable('2026-03-01');
        $parts->remove($golf, $parts->get($golf, $mirror->id), $off, '1500.000', new PartRemoval(), $zone);
        $wing = $mods->create($golf, new ModificationData(
            'Rear wing',
            ModificationCategory::Other,
            new DateTimeImmutable('2026-04-01'),
            '2000.000',
            '150.000',
        ), $zone);
        $mods->revert($golf, $wing, new DateTimeImmutable('2026-05-01'), '2500.000', null, [], [], $zone);
        $sources = array_map(
            static fn ($r): OdometerSource => $r->source,
            $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id),
        );
        self::assertSame([
            OdometerSource::PartFitted,
            OdometerSource::PartRemoved,
            OdometerSource::Modification,
            OdometerSource::ModificationRevert,
        ], $sources);
        $odometerCsv = self::body($browser->get('/vehicles/' . $golf->id . '/export/odometer.csv'));

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'odometer', $odometerCsv);
        $preview = $this->preview($browser, $map);
        self::assertStringNotContainsString('Comes from an entry', $preview);
        self::assertStringContainsString('Import 4 rows', $preview);
        $this->commit($browser, $map, $preview);

        $readings = $this->service($app, OdometerReadingRepository::class)->listForVehicle($polo->id);
        self::assertSame(
            ['1000.000', '1500.000', '2000.000', '2500.000'],
            array_map(static fn ($r): string => $r->readingKm, $readings),
        );
        foreach ($readings as $reading) {
            self::assertSame(OdometerSource::Manual, $reading->source);
        }
    }

    public function testBadRowsAreReportedAndOnlySkippedWhenConfirmed(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        // A spreadsheet's export: semicolons, day-first dates, a header the
        // import does not know ("When"), Windows-1252 text.
        $csv = "When;Type;Cost;Currency;Comment\r\n"
            . "27/09/2026;Parking;0;GBP;Free \x80 parking\r\n"
            . "31/02/2026;Tolls;2.50;GBP;\r\n"
            . "01/09/2026;Tolls;3.20;EUR;Toll in France\r\n"
            . "02/09/2026;Snacks;4;GBP;\r\n"
            . "\r\n"
            . "03/09/2026;Fines;-5;GBP;\r\n";
        $map = $this->upload($browser, $golf->id, 'expenses', $csv);
        $mapping = self::body($browser->get($map));
        $guess = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        self::assertSame('0', $guess['map_spent_on'], 'an unknown header ("When"), but it holds dates');
        self::assertSame('1', $guess['map_category']);
        self::assertSame('2', $guess['map_amount']);
        self::assertSame('dmy', $guess['date_order'], 'not ISO, and the owner is British');

        $preview = $this->preview($browser, $map, ['map_spent_on' => '0']);
        self::assertStringContainsString('Import 1 row', $preview);
        self::assertStringContainsString('Skip the 4 rows with problems', $preview);
        self::assertStringContainsString('Enter a valid date', $preview);
        self::assertStringContainsString('In EUR, but the vehicle uses GBP', $preview);
        foreach (['3', '4', '5', '7'] as $line) {
            self::assertMatchesRegularExpression('#<th scope="row" class="table__num tabular">' . $line . '</th>#', $preview);
        }

        $response = $this->commit($browser, $map, $preview);
        self::assertSame(422, $response->getStatusCode(), 'invalid rows need an explicit skip');
        self::assertStringContainsString('4 rows have problems.', self::body($response));
        self::assertSame([], $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id));

        $response = $this->commit($browser, $map, $preview, ['skip_invalid' => '1']);
        self::assertSame(200, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('1 row was imported.', $html);
        self::assertStringContainsString('4 rows were not imported', $html);

        $expenses = $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $expenses);
        self::assertSame('2026-09-27', $expenses[0]->data->spentOn->format('Y-m-d'));
        self::assertSame('0.000', $expenses[0]->data->amount, 'free is fine');
        self::assertSame('Free € parking', $expenses[0]->data->note, 'Windows-1252 read as such');
    }

    public function testMaintenanceAndDocumentsImportWithLabelsDefaultsAndReadings(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        $csv = "\xEF\xBB\xBFDate,Title,Odometer (Miles),Cost,Garage\n2026-08-14,Timing belt,30000,420.5,Kwik Fit\n";
        $map = $this->upload($browser, $golf->id, 'maintenance', $csv);
        $this->commit($browser, $map, $this->preview($browser, $map));
        $entry = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame('other', $entry->data->category->value, 'no category column: other');
        self::assertSame('48280.320', $entry->data->odometerKm);
        self::assertSame('420.500', $entry->data->cost);
        $reading = $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame(OdometerSource::Maintenance, $reading->source);
        self::assertSame('2026-08-14 11:00', $reading->recordedAt->format('Y-m-d H:i'), 'local noon (BST)');

        $csv = "Type,Provider,Reference,Start,Expiry,Cost\n"
            . "insurance,Admiral,POL-1,2026-09-01,2027-08-31,420\n"
            . "Pollution certificate,,PUC 9,2026-01-01,2026-12-31,\n";
        $map = $this->upload($browser, $golf->id, 'documents', $csv);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Import 2 rows', $preview, 'codes and labels both read');
        $this->commit($browser, $map, $preview);
        $documents = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($golf->id);
        self::assertSame(['insurance', 'pollution'], array_map(static fn ($d): string => $d->data->type->value, $documents));
    }

    public function testTheDocumentOdometerRoundTripsAndWritesItsReading(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $browser->post('/vehicles/' . $golf->id . '/documents/new', [
            'type' => 'inspection', 'title' => '', 'provider' => 'Main Street Motors', 'reference' => 'MOT-1',
            'start_on' => '2026-09-01', 'expiry_on' => '2027-08-31', 'cost' => '54.85', 'odometer' => '30000', 'notes' => '',
        ]);
        $csv = self::body($browser->get('/vehicles/' . $golf->id . '/export/documents.csv'));
        $odometerCsv = self::body($browser->get('/vehicles/' . $golf->id . '/export/odometer.csv'));

        // Into another vehicle: the document and its reading, never doubled
        // by the mileage file (its row comes from the document).
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'documents', $csv);
        $this->commit($browser, $map, $this->preview($browser, $map));
        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame('48280.320', $document->data->odometerKm, 'exactly the exported value');
        $readings = $this->service($app, OdometerReadingRepository::class);
        $reading = $readings->listForVehicle($polo->id)[0];
        self::assertSame(OdometerSource::Document, $reading->source);
        self::assertSame('2026-09-01 11:00', $reading->recordedAt->format('Y-m-d H:i'), 'local noon on the start date');

        $map = $this->upload($browser, $polo->id, 'odometer', $odometerCsv);
        self::assertStringContainsString('Comes from an entry', $this->preview($browser, $map));
        $this->commit($browser, $map, $this->preview($browser, $map));
        self::assertCount(1, $readings->listForVehicle($polo->id));

        // Files without the column import as before; an odometer needs a start.
        $map = $this->upload($browser, $polo->id, 'documents', "Type,Reference,Start\ninsurance,POL-1,2026-09-01\n");
        $this->commit($browser, $map, $this->preview($browser, $map));
        self::assertCount(2, $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($polo->id));
        self::assertCount(1, $readings->listForVehicle($polo->id));

        $map = $this->upload($browser, $polo->id, 'documents', "Type,Reference,Odometer (Miles)\ninspection,MOT-2,31000\n");
        self::assertStringContainsString('Add the date it was issued to record the odometer.', $this->preview($browser, $map));
    }

    /**
     * K48 (#455): the export puts a `'` before text a spreadsheet would run
     * as a formula; importing the file takes it off again, in every module's
     * text columns, so the entries come back as they were written. A `'`
     * that guards nothing (`'abc`) is the text's own and stays.
     */
    public function testEveryModulesExportImportsBackWithItsTextAsWritten(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->service($app, FuelService::class)->create($golf, new FuelEntryData(
            new DateTimeImmutable('2026-05-02T08:15:00Z', new DateTimeZone('UTC')),
            '49400.000',
            Fuel::Petrol,
            '38.250',
            '1.529000',
            '58.48',
            false,
            false,
            '@x',
            '-5 off',
        ));
        $this->service($app, OdometerService::class)->create(
            $golf,
            new OdometerReadingData('49500.000', new DateTimeImmutable('2026-05-10T08:00:00Z'), '=SUM(A1)'),
        );
        $this->service($app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(
                self::day('2026-05-12'),
                MaintenanceCategory::Service,
                '+ oil',
                '45.000',
                vendor: '@Garage',
                description: "'abc",
            ),
            new DateTimeZone('Europe/London'),
        );
        $this->service($app, ComplianceService::class)->create(
            $golf,
            new ComplianceDocumentData(
                ComplianceType::Insurance,
                '-',
                '@Admiral',
                '=POL-1',
                self::day('2026-05-01'),
                self::day('2027-04-30'),
                '420.000',
                '+44 20 7946 0000',
            ),
            new DateTimeZone('Europe/London'),
        );
        $this->expense($app, $golf, '2026-05-14', '4.000', note: '-5 off');
        $this->service($app, TripService::class)->create($golf, new TripData(
            self::day('2026-05-15'),
            '@home',
            '=Depot',
            distanceKm: '10.000',
            purpose: '+client',
            notes: "'abc",
        ));

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        foreach (['fuel', 'odometer', 'maintenance', 'documents', 'expenses', 'trips'] as $module) {
            $csv = self::body($browser->get('/vehicles/' . $golf->id . '/export/' . $module . '.csv'));
            self::assertStringContainsString(",'", $csv, $module . ': the export still guards its text');
            $map = $this->upload($browser, $polo->id, $module, $csv);
            $preview = $this->preview($browser, $map);
            self::assertStringContainsString('Import 1 row', $preview, $module);
            self::assertSame(200, $this->commit($browser, $map, $preview)->getStatusCode(), $module);
        }

        $fill = $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame(['@x', '-5 off'], [$fill->data->station, $fill->data->notes]);
        $stations = $this->service($app, StationRepository::class);
        self::assertNotNull($stations->findByName('@x'));
        self::assertNull($stations->findByName("'@x"), 'no guarded name in the station list');
        $manual = array_values(array_filter(
            $this->service($app, OdometerReadingRepository::class)->listForVehicle($polo->id),
            static fn ($r): bool => $r->source === OdometerSource::Manual,
        ));
        self::assertSame('=SUM(A1)', $manual[0]->note);
        $work = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame(['+ oil', '@Garage', "'abc"], [$work->data->title, $work->data->vendor, $work->data->description]);
        $document = $this->service($app, ComplianceDocumentRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame(
            ['-', '@Admiral', '=POL-1', '+44 20 7946 0000'],
            [$document->data->title, $document->data->provider, $document->data->reference, $document->data->notes],
        );
        self::assertSame('-5 off', $this->service($app, ExpenseEntryRepository::class)->listForVehicle($polo->id)[0]->data->note);
        $trip = $this->service($app, TripRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame(
            ['@home', '=Depot', '+client', "'abc"],
            [$trip->data->fromPlace, $trip->data->toPlace, $trip->data->purpose, $trip->data->notes],
        );
    }

    /**
     * K57 (#459): a date written year first is read under every date order,
     * a date alone at noon, while the order still reads its own dates.
     */
    public function testAYearFirstDateImportsUnderADayFirstOrder(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $csv = implode("\n", [
            'Date,Odometer,Litres,Price',
            '2026-04-05T09:15:00,1000,40,1.60',
            '2026-04-06,1300,30,1.45',
            '2026-04-07 09:15,1600,30,1.45',
            '08/04/2026 09:15,1900,30,1.45',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map, ['date_order' => 'dmy']);
        self::assertStringContainsString('Import 4 rows', $preview);
        self::assertStringNotContainsString('Enter a valid date and time', $preview);
        $this->commit($browser, $map, $preview);

        $times = array_map(
            static fn ($e): string => $e->data->filledAt->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d H:i'),
            $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id),
        );
        sort($times);
        self::assertSame(['2026-04-05 09:15', '2026-04-06 12:00', '2026-04-07 09:15', '2026-04-08 09:15'], $times);
    }

    /**
     * K1 (#484, #573): the preview's number format decides how `45,123`
     * and `13.000` read, defaulting to the owner's locale; a cell the
     * format can't read is a problem row, never read the locale's way.
     */
    public function testTheNumberFormatDecidesHowSeparatorsRead(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $readings = $this->service($app, OdometerReadingRepository::class);
        $fills = $this->service($app, FuelEntryRepository::class);
        $csv = "Date,Odometer\n2026-06-01,\"45,123\"\n2026-06-02,13.000\n";

        // English owner: a decimal point by default.
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $map = $this->upload($browser, $golf->id, 'odometer', $csv);
        $mapping = self::body($browser->get($map));
        self::assertStringContainsString('<option value="dot" selected>Decimal point (1,234.56)</option>', $mapping);
        $this->commit($browser, $map, $this->preview($browser, $map, ['distance_unit' => 'km']));
        $km = array_map(static fn ($r): string => $r->readingKm, $readings->listForVehicle($golf->id));
        sort($km);
        self::assertSame(['13.000', '45123.000'], $km);

        // The same file under a decimal comma.
        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'odometer', $csv);
        $preview = $this->preview($browser, $map, ['number_format' => 'comma', 'distance_unit' => 'km']);
        self::assertStringContainsString('name="number_format" value="comma"', $preview, 'the commit keeps the choice');
        $this->commit($browser, $map, $preview);
        $km = array_map(static fn ($r): string => $r->readingKm, $readings->listForVehicle($polo->id));
        sort($km);
        self::assertSame(['45.123', '13000.000'], $km);

        // An amount the decimal comma can't read is a problem row, not read as canonical.
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $map = $this->upload($browser, $fiesta->id, 'fuel', "Date,Odometer,Litres,Total\n2026-06-01 09:00,1000,40,65.83\n");
        $preview = $this->preview($browser, $map, ['number_format' => 'comma', 'distance_unit' => 'km', 'volume_unit' => 'l']);
        self::assertStringContainsString('Enter a number, e.g. 1234.5.', $preview);
        self::assertStringContainsString('Nothing to import', $preview);

        // German owner: a decimal comma by default, for distances, volumes and amounts.
        $this->germanOwner($browser);
        $csv = "Date,Odometer,Litres,Total\n2026-06-01 09:00,13.000,\"45,123\",\"65,83\"\n";
        $map = $this->upload($browser, $fiesta->id, 'fuel', $csv);
        self::assertStringContainsString('<option value="comma" selected>', self::body($browser->get($map)));
        $this->commit($browser, $map, $this->preview($browser, $map, ['volume_unit' => 'l']));
        $saved = $fills->listForVehicle($fiesta->id);
        self::assertCount(1, $saved);
        self::assertSame('13000.000', $saved[0]->data->odometerKm);
        self::assertSame('45.123', $saved[0]->data->volume);
        self::assertSame('65.830', $saved[0]->data->totalCost);

        // ...and under a decimal point, `65,83` is not read the German way.
        $ka = $this->vehicle($app, 'Ford', 'Ka');
        $map = $this->upload($browser, $ka->id, 'fuel', "Date,Odometer,Litres,Total\n2026-06-01 09:00,13.000,40,\"65,83\"\n");
        $preview = $this->preview($browser, $map, ['number_format' => 'dot', 'distance_unit' => 'km', 'volume_unit' => 'l']);
        $response = $this->commit($browser, $map, $preview, ['skip_invalid' => '1']);
        self::assertSame(422, $response->getStatusCode(), 'nothing importable');
        self::assertSame([], $fills->listForVehicle($ka->id));
    }

    /**
     * #581: Logbook's own export writes plain decimals, so a German owner's
     * export imports back as it was, under a decimal point by default.
     */
    public function testAGermanOwnersOwnExportImportsBackUnderADecimalPoint(): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->germanOwner($browser);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->service($app, FuelService::class)->create($golf, new FuelEntryData(
            new DateTimeImmutable('2026-05-02T08:15:00Z', new DateTimeZone('UTC')),
            '48280.320',
            Fuel::Petrol,
            '45.678',
            '1.459000',
            '66.64',
            false,
            false,
            null,
            null,
        ));
        $this->service($app, MaintenanceService::class)->create(
            $golf,
            new MaintenanceEntryData(self::day('2026-05-12'), MaintenanceCategory::Service, 'Oil', '0.500'),
            new DateTimeZone('Europe/Berlin'),
        );
        $this->service($app, TripService::class)->create($golf, new TripData(
            self::day('2026-05-15'),
            'Home',
            'Depot',
            distanceKm: '1234.567',
            purpose: 'Client',
        ));

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        foreach (['fuel', 'maintenance', 'trips'] as $module) {
            $csv = self::body($browser->get('/vehicles/' . $golf->id . '/export/' . $module . '.csv'));
            $map = $this->upload($browser, $polo->id, $module, $csv);
            $mapping = self::body($browser->get($map));
            self::assertStringContainsString('<option value="dot" selected>', $mapping, $module . ': ' . strtok($csv, "\n"));
            $preview = $this->preview($browser, $map);
            self::assertSame(200, $this->commit($browser, $map, $preview)->getStatusCode(), $module);
        }

        $fill = $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame(
            ['48280.320', '45.678', '66.640'],
            [$fill->data->odometerKm, $fill->data->volume, $fill->data->totalCost],
        );
        $work = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame('0.500', $work->data->cost);
        $trip = $this->service($app, TripRepository::class)->listForVehicle($polo->id)[0];
        self::assertSame('1234.567', $trip->data->distanceKm);

        // The export's own words with a comma in a number were written by hand: a decimal comma.
        $csv = "Datum und Uhrzeit,Kilometerstand,Menge\n2026-06-01 09:00,49000,\"45,123\"\n";
        $map = $this->upload($browser, $polo->id, 'fuel', $csv);
        self::assertStringContainsString('<option value="comma" selected>', self::body($browser->get($map)));

        // A hand-made file with the same columns in other words keeps the owner's decimal comma.
        $map = $this->upload($browser, $polo->id, 'fuel', "Datum,Kilometerstand,Liter\n2026-06-01 09:00,\"49.000\",\"40,5\"\n");
        self::assertStringContainsString('<option value="comma" selected>', self::body($browser->get($map)));
    }

    public function testGuards(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $base = '/vehicles/' . $golf->id . '/import/expenses';

        self::assertSame(200, $browser->get($base)->getStatusCode());
        self::assertStringContainsString('Import CSV', self::body($browser->get('/vehicles/' . $golf->id . '/expenses')));

        $response = $browser->post($base, [], ['file' => $this->file("Date,Amount\n", 'empty.csv')]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The file has no rows to import', self::body($response));

        $big = "Date,Amount\n" . str_repeat("2026-01-01,1\n", 5001);
        $response = $browser->post($base, [], ['file' => $this->file($big, 'big.csv')]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('more than 5,000 rows', self::body($response));

        // A staged file belongs to the session that uploaded it.
        $map = $this->upload($browser, $golf->id, 'expenses', "Date,Amount\n2026-01-01,1\n");
        $stranger = new TestBrowser($app);
        $stranger->post('/login', ['username' => 'owner', 'password' => self::PASSWORD]);
        $response = $stranger->get($map);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('That import has expired', self::body($stranger->follow($response)));

        // Archived vehicles are not imported into.
        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);
        self::assertSame(404, $browser->get($base)->getStatusCode());
        self::assertSame(404, $browser->get($map)->getStatusCode());
        self::assertStringNotContainsString('Import CSV', self::body($browser->get('/vehicles/' . $golf->id . '/expenses')));
    }

    /**
     * K13, #561: the two 01:30s of the night the clocks go back export with
     * different offsets and import as two fill-ups, not one.
     */
    public function testTheClockChangeHourExportsWithItsOffsetAndImportsAsTwoRows(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->fillUp($app, $golf, '2026-10-25T00:30:00Z', '1000.000', '40.000', '60.00', pricePerLitre: '1.500000');
        $this->fillUp($app, $golf, '2026-10-25T01:30:00Z', '1500.000', '30.000', '45.00', pricePerLitre: '1.500000');

        $csv = self::body($browser->get('/vehicles/' . $golf->id . '/export/fuel.csv'));
        self::assertStringContainsString('2026-10-25T01:30:00+01:00', $csv);
        self::assertStringContainsString('2026-10-25T01:30:00+00:00', $csv);

        $polo = $this->vehicle($app, 'Volkswagen', 'Polo');
        $map = $this->upload($browser, $polo->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Import 2 rows', $preview);
        self::assertStringNotContainsString('happens twice', $preview, 'an exact time needs no warning');
        $this->commit($browser, $map, $preview);

        $times = array_map(
            static fn ($e): string => $e->data->filledAt->format('Y-m-d H:i'),
            $this->service($app, FuelEntryRepository::class)->listForVehicle($polo->id),
        );
        sort($times);
        self::assertSame(['2026-10-25 00:30', '2026-10-25 01:30'], $times);
    }

    /**
     * K13: an offset-less time is read in the owner's zone as before, except
     * that a time the clocks skip is refused and a repeated one is flagged.
     */
    public function testATimeWithoutAnOffsetThatTheClocksSkipIsRefusedAndOneTheyRepeatIsFlagged(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $csv = implode("\n", [
            'Date,Odometer,Litres,Price',
            '2026-03-29 01:30,1000,40,1.60',
            '2026-03-29 02:30,1300,30,1.45',
            '2026-10-25 01:30,1600,30,1.45',
            '2026-06-01 12:00,1900,30,1.45',
            '2026-10-25T01:30:00Z,2200,30,1.45',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map);
        self::assertSame(1, substr_count($preview, 'doesn’t exist in your time zone'), $preview);
        self::assertSame(1, substr_count($preview, 'happens twice that night'));
        self::assertStringContainsString('Import 4 rows', $preview);
        $response = $this->commit($browser, $map, $preview, ['skip_invalid' => '1']);
        self::assertSame(200, $response->getStatusCode());

        $times = array_map(
            static fn ($e): string => $e->data->filledAt->format('Y-m-d H:i'),
            $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id),
        );
        sort($times);
        // 01:30 repeated → the first (00:30 UTC); 02:30 BST → 01:30 UTC; noon BST → 11:00; the Z row as written.
        self::assertSame(['2026-03-29 01:30', '2026-06-01 11:00', '2026-10-25 00:30', '2026-10-25 01:30'], $times);
    }

    /**
     * K147: an old-format export wrote both 01:30s of the clock-fall-back night as the same
     * text; re-imported into the vehicle it came from, neither is added again.
     */
    public function testAnOldFormatExportOfTheClockChangeNightAddsNothingWhenReimported(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-10-25T00:30:00Z', '1000.000', '40.000', '60.00', pricePerLitre: '1.500000');
        $this->fillUp($app, $golf, '2026-10-25T01:30:00Z', '1500.000', '30.000', '45.00', pricePerLitre: '1.500000');
        $csv = implode("\n", [
            'Date,Odometer (km),Litres,Price',
            '2026-10-25 01:30,1000,40,1.50',
            '2026-10-25 01:30,1500,30,1.50',
            '2026-10-25 01:30,1700,30,1.50',
        ]) . "\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $preview = $this->preview($browser, $map);
        self::assertStringContainsString('Import 1 row', $preview, 'only the new fill-up at 1700 is added');
        $this->commit($browser, $map, $preview);

        self::assertCount(3, $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testAnAbsurdlyLongTimeCellIsAProblemRowAndReadsQuickly(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $csv = "Date,Odometer,Litres,Price\n" . str_repeat('T', 200000) . "!,1000,40,1.60\n";

        $map = $this->upload($browser, $golf->id, 'fuel', $csv);
        $started = microtime(true);
        $preview = $this->preview($browser, $map);

        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertStringContainsString('Enter a valid date and time', $preview);
    }

    private function germanOwner(TestBrowser $browser): void
    {
        $browser->get('/profile');
        $saved = $browser->post('/settings/preferences', [
            'display_name' => 'Owner', 'theme' => 'system', 'distance_unit' => 'km', 'volume_unit' => 'l',
            'consumption_unit' => 'l_per_100km', 'depth_unit' => 'mm', 'currency' => 'EUR', 'locale' => 'de_DE',
            'timezone' => 'Europe/Berlin',
        ]);
        self::assertSame(303, $saved->getStatusCode(), self::body($saved));
    }

    private function upload(TestBrowser $browser, int $vehicleId, string $module, string $csv, string $name = 'data.csv'): string
    {
        $response = $browser->post('/vehicles/' . $vehicleId . '/import/' . $module, [], ['file' => $this->file($csv, $name)]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        return $response->getHeaderLine('Location');
    }

    /**
     * The mapping form as guessed (plus $overrides), submitted for a preview.
     *
     * @param array<string, string> $overrides
     */
    private function preview(TestBrowser $browser, string $map, array $overrides = []): string
    {
        $mapping = self::body($browser->get($map));
        $query = $overrides + Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        $response = $browser->get($map . '?' . http_build_query($query));
        self::assertSame(200, $response->getStatusCode());

        return self::body($response);
    }

    /**
     * @param array<string, string> $extra
     */
    private function commit(TestBrowser $browser, string $map, string $preview, array $extra = []): ResponseInterface
    {
        $form = Html::element(Html::document($preview), 'form[method="post"][action="' . $map . '"]');

        return $browser->post($map, $extra + Html::formValues($form));
    }

    private function file(string $contents, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'text/csv', strlen($contents), UPLOAD_ERR_OK);
    }
}
