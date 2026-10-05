<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Dom\Element;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Printing reports (spec.md §8 *Printing reports*): the print header on
 * each page (what, which vehicle, period, units, date printed), the Print
 * button, a table for every chart, and module toggles. The owner uses UK
 * units and GBP in Europe/London (en_GB); "today" is 27 Sep 2026.
 */
final class PrintReportsTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-27T10:00:00Z';
    private const string UNITS = 'Miles, Litres, mpg (UK)';
    private const string PRINTED = 'Printed 27 Sept 2026';

    /** @var App<ContainerInterface> */
    private App $app;
    private TestBrowser $browser;
    private Vehicle $golf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
        $this->pinClock($this->app, self::NOW);
        $this->browser = $this->signedIn($this->app);
        $this->golf = $this->vehicle($this->app);

        $this->reading($this->app, $this->golf, '10000', '2026-08-31T12:00:00Z');
        $this->fillUp($this->app, $this->golf, '2026-09-01T08:00:00Z', '10010', '40', '60.00');
        $this->fillUp($this->app, $this->golf, '2026-09-10T08:00:00Z', '10600', '38', '58.52');
        $this->fillUp($this->app, $this->golf, '2026-09-20T08:00:00Z', '11200', '41', '61.09');
        $this->maintenance($this->app, $this->golf, '2026-09-14', 'Annual service', '189.99');
        $this->reading($this->app, $this->golf, '11609.344', '2026-09-25T12:00:00Z');
    }

    public function testEachPageHasAPrintHeaderSayingWhatWhichPeriodUnitsAndWhen(): void
    {
        $id = $this->golf->id;
        $fleet = 'Vehicles All vehicles';
        $golf = 'Vehicle Volkswagen Golf · GO19 ABC';
        $custom = '/reports?range=custom&from=2026-02-01&to=2026-03-31';
        $pages = [
            '/reports' => ['Expenses & reports', $fleet, 'Period 1 Oct 2025 – 27 Sept 2026'],
            $custom => ['Expenses & reports', $fleet, 'Period 1 Feb 2026 – 31 Mar 2026'],
            '/reports?include_archived=1' => ['Expenses & reports', $fleet . ' · archived vehicles included', 'Period'],
            '/reports?vehicle=' . $id => ['Expenses & reports', $golf, 'Period 1 Oct 2025 – 27 Sept 2026'],
            '/reports/ownership' => ['Cost of ownership', $fleet, 'Period Each vehicle from purchase to sale or today'],
            '/reports/ownership?vehicle=' . $id => ['Cost of ownership', $golf, 'Period Each vehicle'],
            '/reports/true-cost?vehicle=' . $id => ['True cost', $golf, 'Period Each vehicle'],
            '/upcoming' => ['Coming up', $fleet, 'Period Sept 2026 – Aug 2027'],
            '/upcoming?vehicle=' . $id => ['Coming up', $golf, 'Period Sept 2026 – Aug 2027'],
            '/vehicles/' . $id . '/fuel' => ['Fuel', $golf, 'Period 1 Sept 2026 – 20 Sept 2026'],
            '/vehicles/' . $id . '/odometer' => ['Mileage', $golf, 'Period 31 Aug 2026 – 25 Sept 2026'],
        ];

        foreach ($pages as $path => [$title, $vehicle, $period]) {
            $response = $this->browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $document = Html::document(self::body($response));

            $header = Html::element($document, '.print-report > header.print-header');
            self::assertTrue($header->classList->contains('print-only'), $path . ': the header is for paper only');
            $text = self::headerText($header);
            self::assertStringContainsString($title, $text, $path);
            self::assertStringContainsString($vehicle, $text, $path);
            self::assertStringContainsString($period, $text, $path);
            self::assertStringContainsString('Units ' . self::UNITS, $text, $path);
            self::assertStringContainsString(self::PRINTED, $text, $path);
            self::assertCount(1, $document->querySelectorAll('.print-header'), $path);
            self::assertCount(1, $document->querySelectorAll('h1'), $path . ': the header adds no heading');

            // The Print button: shown by js/app.js, hidden without it, as History's.
            $button = Html::element($document, 'button[data-print]');
            self::assertTrue($button->hasAttribute('hidden'), $path);
            self::assertSame('Print', trim((string) $button->textContent), $path);
        }
    }

    public function testAVehicleWithNothingRecordedSaysSo(): void
    {
        $bare = $this->vehicle($this->app, 'Ford', 'Fiesta');

        foreach (['/vehicles/' . $bare->id . '/fuel', '/vehicles/' . $bare->id . '/odometer'] as $path) {
            $header = Html::element(Html::document(self::body($this->browser->get($path))), '.print-header');
            self::assertStringContainsString('Period Nothing recorded yet', self::headerText($header), $path);
        }
    }

    public function testEveryChartHasATableThatPrints(): void
    {
        $id = $this->golf->id;
        $charts = 0;
        $paths = [
            '/reports', '/reports?vehicle=' . $id, '/upcoming',
            '/vehicles/' . $id . '/fuel', '/vehicles/' . $id . '/odometer',
        ];
        foreach ($paths as $path) {
            $document = Html::document(self::body($this->browser->get($path)));
            foreach ($document->querySelectorAll('canvas[data-chart]') as $canvas) {
                $card = $canvas->closest('.card, [data-trend-panel]');
                self::assertInstanceOf(Element::class, $card, $path);
                self::assertNotNull($card->querySelector('table'), $path . ': a chart without a table');
                $charts++;
            }
        }
        self::assertGreaterThanOrEqual(5, $charts, 'reports, fuel (economy, cost, price) and mileage charts drawn');

        // A line chart's own points, newest first, in the owner's units.
        $mileage = Html::document(self::body($this->browser->get('/vehicles/' . $id . '/odometer')));
        $table = Html::element($mileage, '.print-only > table.chart-print-table');
        $caption = Html::element($mileage, '.chart-print-table caption');
        self::assertStringContainsString('Odometer over time · Miles', self::text($caption));
        $rows = self::rows($table);
        self::assertSame(['25 Sept 2026', '7,214'], $rows[0], '11,609.344 km');
        self::assertSame(['31 Aug 2026', '6,214'], $rows[array_key_last($rows)]);

        $fuel = Html::document(self::body($this->browser->get('/vehicles/' . $id . '/fuel')));
        $tables = $fuel->querySelectorAll('table.chart-print-table');
        self::assertCount(2, $tables, 'economy and price trends');
        $price = self::rows($tables->item(1));
        self::assertSame(['20 Sept 2026', '£1.49'], $price[0], 'price per litre, at the chart’s precision');
        // Both Economy | Cost panels are in the page; print CSS shows the hidden one.
        self::assertCount(2, $fuel->querySelectorAll('.print-report [data-trend-panel]'));
    }

    public function testASwitchedOffModuleStillAnswers404AndLeavesTheOtherPrints(): void
    {
        $settings = $this->service($this->app, SettingRepository::class);
        $settings->save(FeatureToggles::SETTING, ['fuel' => false], SettingScope::Global);

        self::assertSame(404, $this->browser->get('/vehicles/' . $this->golf->id . '/fuel')->getStatusCode());
        $report = self::body($this->browser->get('/reports'));
        self::assertStringContainsString('print-header', $report);
        self::assertStringNotContainsString('£60.00', $report, 'no fuel figure in the printed report');
        $mileage = Html::document(self::body($this->browser->get('/vehicles/' . $this->golf->id . '/odometer')));
        self::assertStringContainsString('Mileage', self::text(Html::element($mileage, '.print-header')));

        $settings->save(FeatureToggles::SETTING, ['reports' => false], SettingScope::Global);
        self::assertSame(404, $this->browser->get('/reports')->getStatusCode());
        self::assertSame(404, $this->browser->get('/reports/ownership')->getStatusCode());
        self::assertSame(404, $this->browser->get('/reports/true-cost')->getStatusCode());
    }

    public function testAnArchivedVehiclePrintsItsTabs(): void
    {
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $this->golf);

        $fuel = Html::document(self::body($this->browser->get('/vehicles/' . $this->golf->id . '/fuel')));
        self::assertStringContainsString('Volkswagen Golf', self::text(Html::element($fuel, '.print-header')));
        Html::element($fuel, 'button[data-print]');
    }

    /** The header's lines, each label followed by its value. */
    private static function headerText(Element $header): string
    {
        return implode(' ', array_map(
            static fn (Element $part): string => self::text($part),
            iterator_to_array($header->querySelectorAll('p, dt, dd')),
        ));
    }

    private static function text(Element $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }

    /**
     * @return list<list<string>> each body row's cells' text
     */
    private static function rows(?Element $table): array
    {
        self::assertNotNull($table);
        $rows = [];
        foreach ($table->querySelectorAll('tbody tr') as $row) {
            $rows[] = array_values(array_map(
                static fn (Element $cell): string => self::text($cell),
                iterator_to_array($row->querySelectorAll('th, td')),
            ));
        }

        return $rows;
    }
}
