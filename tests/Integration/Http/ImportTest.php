<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Vehicle\VehicleService;
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
