<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Setting\SettingScope;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\SettingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Cost of ownership end to end (docs/phases/phase-14.2.md): the overview card, the
 * ownership report and its CSV, the finance category, modules, and every
 * existing report figure left as it was. The owner uses UK units and GBP;
 * "today" is 1 Sep 2026.
 */
final class OwnershipCostTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-01T10:00:00Z';
    /** 10,000 km, then 36,000 and 39,000 miles on. */
    private const string AT_PURCHASE = '10000';
    private const string AT_VALUATION = '67936.384';
    private const string TODAY_KM = '72764.416';

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

    public function testTheOverviewCardAddsRunningCostsAndDepreciation(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->workedExample($app);

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'cost-heading');

        self::assertStringContainsString('Cost of ownership', $card);
        self::assertStringContainsString('3 yrs 6 mo, since 1 Mar 2023', $card);
        self::assertStringContainsString('39,000 mi', $card, 'distance owned');
        self::assertStringContainsString('£11,700.00', $card, 'running costs');
        self::assertStringContainsString('Fuel <span class="tabular">£7,000.00</span>', $card);
        self::assertStringContainsString('Other <span class="tabular">£4,700.00</span>', $card);
        self::assertStringNotContainsString('£500.00', $card, 'the deposit the day before the purchase');
        self::assertStringContainsString('£5,200.00', $card, 'depreciation');
        self::assertStringContainsString('£16,900.00', $card);
        self::assertStringContainsString('(depreciation to 1 Mar 2026)', $card);
        self::assertStringContainsString('£0.444/mi', $card);
        self::assertStringContainsString('£0.30/mi running + £0.144/mi depreciation', $card);
        self::assertStringContainsString('£416.54', $card, 'per month');
        self::assertStringContainsString('href="/reports/ownership?vehicle=' . $golf->id . '"', $card);
    }

    public function testWithoutAPurchasePriceTheCardShowsRunningCostsAlone(): void
    {
        [$app, $browser] = $this->start();
        $ev = $this->car($app, 'Kia', 'EV6', purchased: '2024-02-10');
        $this->reading($app, $ev, '100', '2024-02-10T12:00:00Z');
        $this->expense($app, $ev, '2024-03-10', '449', ExpenseCategory::Finance, 'Lease payment');
        $this->expense($app, $ev, '2024-04-10', '449', ExpenseCategory::Finance, 'Lease payment');
        $this->reading($app, $ev, '20100', '2026-08-01T12:00:00Z');

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $ev->id)), 'cost-heading');

        self::assertStringContainsString('Running costs since 10 Feb 2024', $card);
        self::assertStringContainsString('£898.00', $card);
        self::assertStringNotContainsString('Total so far', $card, 'never shown as if complete');
        self::assertStringContainsString('running costs only', $card);
        self::assertStringContainsString('Add what you paid to see depreciation.', $card);
    }

    public function testAMileageLogStartingLateHidesTheDistanceWithAHint(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->car($app, purchased: '2023-03-01', price: '15000');
        $this->expense($app, $golf, '2023-06-01', '1000');
        $this->reading($app, $golf, '50000', '2026-01-15T12:00:00Z');
        $this->reading($app, $golf, '55000', '2026-08-15T12:00:00Z');

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'cost-heading');

        self::assertStringNotContainsString('Distance owned', $card);
        self::assertStringNotContainsString('/mi', $card);
        self::assertStringContainsString('Your mileage log starts on 15 Jan 2026', $card);
    }

    public function testASoldVehicleShowsExactLifetimeFigures(): void
    {
        [$app, $browser] = $this->start();
        $fiesta = $this->fiesta($app);
        $this->reading($app, $fiesta, '20000', '2016-06-30T11:00:00Z');
        $this->expense($app, $fiesta, '2020-01-01', '3000');
        $this->reading($app, $fiesta, '90000', '2025-11-20T12:00:00Z');
        $this->expense($app, $fiesta, '2026-01-10', '60', note: 'A fine after the sale');

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $fiesta->id)), 'cost-heading');

        self::assertStringContainsString('30 Jun 2016 – 20 Nov 2025', $card);
        self::assertStringContainsString('£7,400.00', $card);
        self::assertStringContainsString('(Lifetime, sold 20 Nov 2025)', $card);
        self::assertStringNotContainsString('£3,060.00', $card, 'after the sale is outside the period');
    }

    public function testTheOwnershipReportGroupsByCurrencyAndLeavesArchivedOut(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->workedExample($app);
        $fiesta = $this->fiesta($app);
        $this->expense($app, $fiesta, '2020-01-01', '3000');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $fiesta);
        $ev = $this->car($app, 'Kia', 'EV6', currency: 'EUR', purchased: '2024-02-10');
        $this->expense($app, $ev, '2024-03-10', '449', ExpenseCategory::Finance);
        $nothing = $this->car($app, 'Toyota', 'Corolla');

        $response = $browser->get('/reports/ownership');
        self::assertSame(200, $response->getStatusCode());
        $html = self::body($response);
        self::assertStringContainsString('<h1 class="page-header__title">Cost of ownership</h1>', $html);
        self::assertStringContainsString('In British Pound', $html);
        self::assertStringContainsString('In Euro', $html);
        self::assertStringContainsString('£16,900.00', $html);
        self::assertStringContainsString('€449.00', $html);
        self::assertStringNotContainsString('Fiesta</a>', $html, 'archived: left out by default');
        self::assertStringNotContainsString('Corolla</a>', $html, 'no ownership period');
        self::assertStringContainsString('sold vehicles have exact lifetime figures', $html);
        self::assertStringNotContainsString('£17,349', $html, 'currencies never added');

        $included = self::body($browser->get('/reports/ownership?include_archived=1'));
        self::assertStringContainsString('Fiesta</a>', $included);
        self::assertStringContainsString('Lifetime, sold 20 Nov 2025', $included);
        self::assertStringContainsString('Fleet', $included);
        self::assertStringContainsString('£24,300.00', $included, '£16,900 + £7,400');

        $one = self::body($browser->get('/reports/ownership?vehicle=' . $fiesta->id));
        self::assertStringContainsString('Fiesta</a>', $one, 'picking an archived vehicle includes it');
        self::assertStringNotContainsString('Golf</a>', $one);

        self::assertStringContainsString(
            'href="/reports/ownership"',
            self::body($browser->get('/reports')),
            'linked from the Reports header',
        );
        self::assertSame(200, $browser->get('/vehicles/' . $nothing->id)->getStatusCode());
        self::assertStringNotContainsString('cost-heading', self::body($browser->get('/vehicles/' . $nothing->id)));
    }

    public function testTheOwnershipCsvIsOneRowPerVehicle(): void
    {
        [$app, $browser] = $this->start();
        $this->workedExample($app);
        $fiesta = $this->car($app, 'Ford', 'Fiesta', purchased: '2016-06-30', sold: '2025-11-20');
        $this->expense($app, $fiesta, '2020-01-01', '3000');
        $this->service($app, VehicleService::class)->archive($this->owner($app), $fiesta);

        $response = $browser->get('/reports/ownership.csv');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="logbook-ownership-2026-09-01.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
        $csv = self::body($response);
        self::assertStringStartsWith(CsvWriter::BOM, $csv);
        $rows = self::rows($csv);
        self::assertCount(2, $rows, 'a header and the Golf: archived left out');
        self::assertSame([
            'Vehicle', 'Registration', 'Currency', 'Owned from', 'Owned to', 'Started', 'Sold', 'Distance owned (Miles)',
            'Running costs: Fuel', 'Running costs: Maintenance', 'Running costs: Documents', 'Running costs: Other',
            'Insurance payouts', 'Running costs', 'Depreciation', 'Depreciation to', 'Total',
            'Running per distance (per mi)', 'Depreciation per distance (per mi)', 'Total per distance (per mi)',
            'Running per month', 'Depreciation per month', 'Total per month',
        ], $rows[0]);
        $golf = array_combine($rows[0], $rows[1]);
        self::assertSame('Volkswagen Golf', $golf['Vehicle']);
        self::assertSame('2023-03-01', $golf['Owned from']);
        self::assertSame('2026-09-01', $golf['Owned to']);
        self::assertSame('Purchase', $golf['Started']);
        self::assertSame('no', $golf['Sold']);
        self::assertSame('39000', $golf['Distance owned (Miles)']);
        self::assertSame('7000.00', $golf['Running costs: Fuel']);
        self::assertSame('11700.00', $golf['Running costs']);
        self::assertSame('5200.00', $golf['Depreciation']);
        self::assertSame('2026-03-01', $golf['Depreciation to']);
        self::assertSame('16900.00', $golf['Total']);
        self::assertEqualsWithDelta(0.444, (float) $golf['Total per distance (per mi)'], 0.0005);
        self::assertSame('416.537', $golf['Total per month']);

        $all = self::rows(self::body($browser->get('/reports/ownership.csv?include_archived=1')));
        self::assertCount(3, $all);
        $sold = array_combine($all[0], $all[2]);
        self::assertSame('yes', $sold['Sold'], 'sold, although without prices it has no lifetime total');
        self::assertSame('', $sold['Total']);
    }

    public function testModulesAndTheCardStayingWhenReportsAreOff(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->car($app, purchased: '2026-01-01');
        $this->fillUp($app, $golf, '2026-02-10T08:00:00Z', '10400', '40', '60.00');
        $this->expense($app, $golf, '2026-03-20', '2.5');

        $settings = $this->service($app, SettingRepository::class);
        $settings->save(FeatureToggles::SETTING, ['fuel' => false], SettingScope::Global);
        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'cost-heading');
        self::assertStringContainsString('£2.50', $card);
        self::assertStringNotContainsString('£62.50', $card, 'fill-up costs leave with the fuel module');

        $settings->save(FeatureToggles::SETTING, ['reports' => false], SettingScope::Global);
        self::assertSame(404, $browser->get('/reports/ownership')->getStatusCode());
        self::assertSame(404, $browser->get('/reports/ownership.csv')->getStatusCode());
        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'cost-heading');
        self::assertStringContainsString('£62.50', $card, 'the card is core');
        self::assertStringNotContainsString('/reports/ownership', $card);
    }

    public function testTyreCostsAreCountedOnce(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->car($app, purchased: '2026-01-01');
        $response = $browser->post('/vehicles/' . $golf->id . '/tyres/fit', [
            'done_on' => '2026-05-20',
            'odometer' => '21000',
            'pos_fl' => '1',
            'pos_fr' => '1',
            'brand' => 'Michelin',
            'model' => 'Primacy 4',
            'size' => '205/55 R16 91V',
            'season' => 'summer',
            'dot_fl' => '3025',
            'dot_fr' => '3125',
            'cost' => '240',
            'vendor' => 'Kwik Fit',
            'note' => '',
        ]);
        self::assertSame(303, $response->getStatusCode(), self::body($response));

        $card = self::cardOf(self::body($browser->get('/vehicles/' . $golf->id)), 'cost-heading');
        self::assertStringContainsString('Maintenance <span class="tabular">£240.00</span>', $card);
        self::assertStringNotContainsString('£480.00', $card);
    }

    public function testTheFinanceCategoryThroughTheFormAndImport(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->car($app);
        $base = '/vehicles/' . $golf->id . '/expenses';

        $form = self::body($browser->get($base . '/new'));
        self::assertStringContainsString('<option value="finance">Finance and lease</option>', $form);
        self::assertStringContainsString('log only the interest and fees', $form);

        $created = $browser->post(
            $base . '/new',
            ['spent_on' => '2026-08-05', 'category' => 'finance', 'amount' => '299', 'note' => 'PCP'],
        );
        self::assertSame(303, $created->getStatusCode(), self::body($created));
        self::assertStringContainsString('Finance and lease', self::body($browser->get($base)));

        $csv = "Date,Category,Amount,Currency,Note\n2026-07-05,Finance and lease,299,GBP,PCP\n2026-06-05,finance,299,GBP,PCP\n";
        $upload = $browser->post('/vehicles/' . $golf->id . '/import/expenses', [], ['file' => $this->csvFile($csv)]);
        self::assertSame(303, $upload->getStatusCode(), self::body($upload));
        $map = $upload->getHeaderLine('Location');
        $mapping = self::body($browser->get($map));
        $query = Html::formValues(Html::element(Html::document($mapping), 'form[method="get"]'));
        $preview = self::body($browser->get($map . '?' . http_build_query($query)));
        self::assertStringContainsString('Import 2 rows', $preview);
        $commit = Html::formValues(Html::element(Html::document($preview), 'form[method="post"][action="' . $map . '"]'));
        self::assertSame(200, $browser->post($map, $commit)->getStatusCode());

        $categories = array_map(
            static fn ($e): string => $e->data->category->value,
            $this->service($app, ExpenseEntryRepository::class)->listForVehicle($golf->id),
        );
        self::assertSame(['finance', 'finance', 'finance'], $categories, 'by label and by code');
    }

    public function testExistingReportFiguresAreUnchanged(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->workedExample($app);
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2026-08-01'), '9000'));

        // The last 12 months of the worked example: the fill-up and the other costs are older.
        $report = self::body($browser->get('/reports?vehicle=' . $golf->id . '&range=all'));
        self::assertStringContainsString('£12,200.00', $report, 'all time, the deposit included; values are never costs');
        self::assertStringNotContainsString('£16,900', $report);
        self::assertStringNotContainsString('£17,700', $report);
        $expenses = self::body($browser->get('/vehicles/' . $golf->id . '/expenses?range=all'));
        // The tab's own figures leave values out; the true cost card below them is Phase 32's.
        $own = strstr($expenses, 'aria-labelledby="true-cost-heading"', true);
        self::assertNotFalse($own);
        self::assertStringNotContainsString('Depreciation', $own);
    }

    /**
     * The phase's worked example: bought £15,000 on 1 Mar 2023, valued
     * £9,800 on 1 Mar 2026 (36,000 mi in between), £11,700 of running
     * costs and 39,000 mi from purchase to today; and a £500 deposit the
     * day before the purchase.
     *
     * @param App<ContainerInterface> $app
     */
    private function workedExample(App $app): Vehicle
    {
        $golf = $this->car($app, purchased: '2023-03-01', price: '15000');
        $this->reading($app, $golf, self::AT_PURCHASE, '2023-03-01T12:00:00Z');
        $this->expense($app, $golf, '2023-02-28', '500', ExpenseCategory::Other, 'Deposit');
        $this->fillUp($app, $golf, '2023-06-01T08:00:00Z', '12000', '4000', '7000.00');
        $this->expense($app, $golf, '2024-05-10', '4700', ExpenseCategory::Other, 'Everything else');
        $this->reading($app, $golf, self::AT_VALUATION, '2026-03-01T12:00:00Z');
        $this->reading($app, $golf, self::TODAY_KM, '2026-08-31T12:00:00Z');
        $this->service($app, ValuationService::class)->create($golf, new VehicleValuationData(self::date('2026-03-01'), '9800'));

        return $golf;
    }

    /**
     * Bought £6,500 on 30 Jun 2016, sold £2,100 on 20 Nov 2025.
     *
     * @param App<ContainerInterface> $app
     */
    private function fiesta(App $app): Vehicle
    {
        return $this->car($app, 'Ford', 'Fiesta', purchased: '2016-06-30', price: '6500', sold: '2025-11-20', salePrice: '2100');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function car(
        App $app,
        string $make = 'Volkswagen',
        string $model = 'Golf',
        ?string $currency = null,
        ?string $purchased = null,
        ?string $price = null,
        ?string $sold = null,
        ?string $salePrice = null,
    ): Vehicle {
        return $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            $make,
            $model,
            FuelType::Petrol,
            registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
            currency: $currency,
            purchaseDate: $purchased === null ? null : self::date($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::date($sold),
            salePrice: $salePrice,
        ));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser}
     */
    private function start(): array
    {
        $app = $this->createApp();
        $this->pinClock($app, self::NOW);

        return [$app, $this->signedIn($app)];
    }

    private function csvFile(string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-import-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'expenses.csv', 'text/csv', strlen($contents), UPLOAD_ERR_OK);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }

    private static function cardOf(string $html, string $headingId): string
    {
        $at = strpos($html, 'aria-labelledby="' . $headingId . '"');
        self::assertNotFalse($at, $headingId);
        $end = strpos($html, '</section>', $at);

        return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, strlen(CsvWriter::BOM)));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($stream);

        return $rows;
    }
}
