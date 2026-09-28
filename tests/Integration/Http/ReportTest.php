<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Reports end to end: fleet and single-vehicle figures, archived vehicles
 * left out by default, several currencies side by side, the date filter,
 * feature toggles and the CSV export. The owner uses UK units and GBP;
 * "today" is 27 Sep 2026.
 */
final class ReportTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';

    public function testFleetReportWithArchivedVehiclesLeftOutByDefault(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $sold = $this->vehicle($app, 'Ford', 'Fiesta');

        $this->reading($app, $golf, '10000', '2026-08-31T12:00:00Z');
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10400', '40', '60.00');
        $this->maintenance($app, $golf, '2026-08-14', 'Annual service', '189.99');
        $this->expense($app, $golf, '2026-09-20', '2.5', ExpenseCategory::Tolls);
        $this->reading($app, $golf, '11609.344', '2026-09-25T12:00:00Z');
        $this->expense($app, $sold, '2026-09-01', '1000', ExpenseCategory::Other, 'Sold car repairs');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $sold);

        $response = $browser->get('/reports');
        self::assertSame(200, $response->getStatusCode());
        $html = self::body($response);

        self::assertStringContainsString('Expenses &amp; reports', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('1 Oct 2025 – 27 Sept 2026', $html, 'last 12 months by default');
        self::assertStringContainsString('£252.49', $html, 'the archived car’s £1,000 is left out');
        self::assertStringNotContainsString('£1,252.49', $html);
        self::assertStringContainsString('3 costs', $html);
        // Distance from the mileage log: the first reading in the period to the last.
        self::assertStringContainsString('1,000 mi', $html, '1,609.344 km driven');
        self::assertStringContainsString('£0.252/mi', $html);
        self::assertStringContainsString('£21.04', $html, '£252.49 over 12 months');
        // Spend per month: a table (works without JS) and a chart.
        self::assertStringContainsString('<table class="table">', $html);
        self::assertStringContainsString('Sept 2026', $html);
        self::assertStringContainsString('data-chart="', $html);
        self::assertStringContainsString('&quot;type&quot;:&quot;bar&quot;', $html);

        $included = self::body($browser->get('/reports?include_archived=1'));
        self::assertStringContainsString('£1,252.49', $included);
        self::assertStringContainsString('archived vehicles included', $included);
        self::assertStringContainsString('By vehicle', $included);

        // Picking the archived vehicle is explicit: its costs show.
        $picked = self::body($browser->get('/reports?vehicle=' . $sold->id));
        self::assertStringContainsString('£1,000.00', $picked);
        self::assertStringNotContainsString('By vehicle', $picked);
    }

    public function testDateRangesAndSeveralCurrencies(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $vespa = $this->vehicle($app, 'Piaggio', 'Vespa', 'EUR', type: VehicleType::Bike);

        $this->expense($app, $golf, '2026-02-10', '12');
        $this->expense($app, $golf, '2026-09-05', '30');
        $this->expense($app, $vespa, '2026-09-06', '7.5');
        $this->document($app, $vespa, ComplianceType::Insurance, '2026-03-01', '2027-02-28', '99');

        $month = self::body($browser->get('/reports?range=month'));
        self::assertStringContainsString('In British Pound', $month);
        self::assertStringContainsString('In Euro', $month);
        self::assertStringContainsString('£30.00', $month);
        self::assertStringContainsString('€7.50', $month);
        self::assertStringNotContainsString('£37.50', $month, 'pounds and euros are never added');

        $custom = self::body($browser->get('/reports?range=custom&from=2026-02-01&to=2026-03-31'));
        self::assertStringContainsString('1 Feb 2026 – 31 Mar 2026', $custom);
        self::assertStringContainsString('£12.00', $custom);
        self::assertStringContainsString('€99.00', $custom);
        self::assertStringNotContainsString('£30.00', $custom);

        // A bookmarkable URL: the filter is kept in the form and the export link.
        self::assertStringContainsString('value="2026-02-01"', $custom);
        self::assertStringContainsString(
            'href="/reports/export.csv?range=custom&amp;from=2026-02-01&amp;to=2026-03-31"',
            $custom,
        );
    }

    public function testASwitchedOffModuleLeavesTheReport(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10400', '40', '60.00');
        $this->expense($app, $golf, '2026-09-20', '2.5');

        $this->service($app, SettingRepository::class)->save(FeatureToggles::SETTING, ['fuel' => false], SettingScope::Global);
        $html = self::body($browser->get('/reports'));
        self::assertStringContainsString('£2.50', $html);
        self::assertStringNotContainsString('£62.50', $html);

        $this->service($app, SettingRepository::class)->save(FeatureToggles::SETTING, ['reports' => false], SettingScope::Global);
        $garage = self::body($browser->get('/garage'));
        self::assertStringNotContainsString('href="/reports"', $garage, 'no Reports entry in the navigation');
    }

    public function testReportExportIsOneRowPerCost(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10400', '40', '60.00');
        $this->expense($app, $golf, '2026-09-20', '0', ExpenseCategory::Parking, '=cmd|calc');
        $this->maintenance($app, $golf, '2026-09-14', 'Brakes, front', '189.5');

        $response = $browser->get('/reports/export.csv?range=month');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="logbook-report-2026-09-01-2026-09-27.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));

        $csv = self::body($response);
        self::assertStringStartsWith(CsvWriter::BOM, $csv);
        self::assertSame([
            ['Date', 'Vehicle', 'Registration', 'Group', 'Kind', 'Description', 'Amount', 'Currency'],
            ['2026-09-10', 'Volkswagen Golf', 'GO19 ABC', 'Fuel', 'Petrol', '', '60.00', 'GBP'],
            ['2026-09-14', 'Volkswagen Golf', 'GO19 ABC', 'Maintenance', 'Service', 'Brakes, front', '189.50', 'GBP'],
            ['2026-09-20', 'Volkswagen Golf', 'GO19 ABC', 'Other', 'Parking', "'=cmd|calc", '0.00', 'GBP'],
        ], self::rows($csv));
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(string $csv): array
    {
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
