<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\UserRepository;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Per-vehicle CSV exports: headers in the owner's language with units,
 * values in the owner's units (miles and UK gallons here) as plain decimals
 * that convert back to exactly what is stored.
 */
final class CsvExportTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    public function testFuelExportRoundTripsAtTheStoredPrecision(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf GTI');
        // Imperial gallons, so volumes and prices really go through a conversion.
        $owner = $this->owner($app);
        $prefs = $owner->preferences;
        $this->service($app, UserRepository::class)->updateProfile(
            $owner->id,
            $owner->displayName,
            new DisplayPreferences(
                $prefs->locale,
                $prefs->timezone,
                $prefs->distanceUnit,
                VolumeUnit::UkGallon,
                $prefs->consumptionUnit,
                'GBP',
            ),
            new DateTimeImmutable(self::NOW),
        );
        // 00:30 BST on 1 April: the local time is exported, with the zone in the header.
        $this->fillUp(
            $app,
            $golf,
            '2026-03-31T23:30:00Z',
            '48280.320',
            '45.678',
            '66.64',
            pricePerLitre: '1.459000',
            grade: FuelGrade::E5_97,
        );
        $this->fillUp($app, $golf, '2026-04-20T17:05:00Z', '48885.123', '40.001', '0', partial: true, pricePerLitre: '0.000000');

        $response = $browser->get('/vehicles/' . $golf->id . '/export/fuel.csv');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(
            'attachment; filename="logbook-volkswagen-golf-gti-fuel-2026-09-27.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );

        $rows = self::rows(self::body($response));
        self::assertSame([
            'Date and time (Europe/London)', 'Odometer (Miles)', 'Fuel', 'Grade', 'Grade code', 'Volume', 'Unit',
            'Price per unit', 'Total', 'Currency', 'Partial', 'Missed previous', 'Station', 'Notes',
        ], $rows[0]);
        self::assertSame(
            [
                '2026-04-01 00:30', '30000', 'Petrol', 'E5 super unleaded, 97 RON', 'e5_97', '10.047755', 'UK gallons',
                '6.63274531', '66.64', 'GBP', 'no', 'no', '', '',
            ],
            $rows[1],
        );
        self::assertSame('2026-04-20 18:05', $rows[2][0]);
        self::assertSame(['', ''], [$rows[2][3], $rows[2][4]], 'no grade recorded: both grade cells empty');
        self::assertSame('0.00', $rows[2][8], 'a free fill-up exports as 0');
        self::assertSame('yes', $rows[2][10]);

        // Converting the exported figures back gives exactly what is stored.
        foreach ($this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id) as $i => $entry) {
            $row = $rows[$i + 1];
            self::assertSame($entry->data->odometerKm, DistanceUnit::Mile->toKmDecimal($row[1], 3));
            self::assertSame($entry->data->grade->value ?? '', $row[4]);
            self::assertSame($entry->data->volume, VolumeUnit::UkGallon->toLitresDecimal($row[5], 3));
            self::assertSame($entry->data->pricePerUnit, VolumeUnit::UkGallon->pricePerLitre($row[7], 6));
            self::assertSame($entry->data->totalCost, number_format((float) $row[8], 3, '.', ''));
        }
    }

    public function testEveryModuleExports(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->reading($app, $golf, '1000.5', '2026-09-01T09:00:00Z');
        $this->maintenance($app, $golf, '2026-09-14', 'Annual service', '189.5', '1609.344');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-09-01', '2027-08-31', '420', 'Admiral');
        $this->expense($app, $golf, '2026-09-20', '0', ExpenseCategory::Parking, 'Free, for once');
        $base = '/vehicles/' . $golf->id . '/export/';

        $odometer = self::rows(self::body($browser->get($base . 'odometer.csv')));
        self::assertSame(['Date and time (Europe/London)', 'Odometer (Miles)', 'Source', 'Note'], $odometer[0]);
        self::assertSame(['2026-09-01 10:00', '621.681878', 'Manual', ''], $odometer[1]);
        self::assertSame(['2026-09-14 12:00', '1000', 'Service', ''], $odometer[2], 'the maintenance reading, at local noon');
        $stored = $this->service($app, OdometerReadingRepository::class)->listForVehicle($golf->id)[0]->readingKm;
        self::assertSame($stored, DistanceUnit::Mile->toKmDecimal($odometer[1][1], 3));

        $maintenance = self::rows(self::body($browser->get($base . 'maintenance.csv')));
        self::assertSame(
            ['Date', 'Category', 'Title', 'Odometer (Miles)', 'Cost', 'Currency', 'Garage', 'Details'],
            $maintenance[0],
        );
        self::assertSame(['2026-09-14', 'Service', 'Annual service', '1000', '189.50', 'GBP', '', ''], $maintenance[1]);
        $entry = $this->service($app, MaintenanceEntryRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame($entry->data->odometerKm, DistanceUnit::Mile->toKmDecimal($maintenance[1][3], 3));

        $documents = self::rows(self::body($browser->get($base . 'documents.csv')));
        self::assertSame(
            ['Type', 'Title', 'Provider', 'Reference', 'Start', 'Expiry', 'Odometer (Miles)', 'Cost', 'Currency', 'Notes'],
            $documents[0],
        );
        self::assertSame(['Insurance', '', 'Admiral', '', '2026-09-01', '2027-08-31', '', '420.00', 'GBP', ''], $documents[1]);

        $expenses = self::rows(self::body($browser->get($base . 'expenses.csv')));
        self::assertSame(['Date', 'Category', 'Amount', 'Currency', 'Note'], $expenses[0]);
        self::assertSame(['2026-09-20', 'Parking', '0.00', 'GBP', 'Free, for once'], $expenses[1]);

        self::assertSame(404, $browser->get($base . 'passwords.csv')->getStatusCode());

        // An archived vehicle's data still exports.
        $this->service($app, VehicleService::class)->archive($this->owner($app), $golf);
        self::assertSame(200, $browser->get($base . 'expenses.csv')->getStatusCode());
    }

    public function testTabsLinkToTheirExport(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);

        foreach (['odometer', 'fuel', 'maintenance', 'documents', 'expenses'] as $module) {
            $html = self::body($browser->get('/vehicles/' . $golf->id . '/' . $module));
            self::assertStringContainsString('href="/vehicles/' . $golf->id . '/export/' . $module . '.csv"', $html, $module);
        }
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(string $csv): array
    {
        self::assertStringStartsWith(CsvWriter::BOM, $csv);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, strlen(CsvWriter::BOM)));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => $cell ?? '', $row);
        }
        fclose($stream);

        return $rows;
    }
}
