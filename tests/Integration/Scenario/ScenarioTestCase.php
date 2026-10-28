<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scenario;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\ApiClient;
use Logbook\Tests\Support\ApiFixtures;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Whole-life finance scenarios (Phase 54.1, docs/phases/phase-54.md): one
 * vehicle built from purchase to sale or today, then its cost of
 * ownership read three ways (the vehicle's Cost of ownership page, the
 * API with `?include=working`, and the ownership CSV) and checked against
 * a golden file in tests/Fixtures/golden/<scenario>.json that was worked
 * out by hand from spec.md §7.7 and §7.35 (each file says how). A golden
 * value is never copied from the app's output: when the two disagree the
 * test fails and the disagreement is a finding.
 *
 * Golden file shape:
 *   ownership.api   dotted path in the API document => expected string
 *   ownership.csv   CSV column => expected cell
 *   ownership.page  strings the page's text must contain
 *   true_cost       since_bought and years: totals and parts as decimal strings, plus
 *                   each year's CSV year and total
 */
abstract class ScenarioTestCase extends AppTestCase
{
    use ApiFixtures;
    use CostFixtures;

    protected const string NOW = '2026-09-01T10:00:00Z';

    protected function tearDown(): void
    {
        self::clearThrottle();
        parent::tearDown();
    }

    /**
     * The app with the clock pinned, a signed-in owner (UK units and GBP unless
     * told otherwise) and an API client for the same user.
     *
     * @return array{0: App<ContainerInterface>, 1: TestBrowser, 2: ApiClient}
     */
    protected function start(string $now = self::NOW): array
    {
        $app = $this->createApp();
        $this->pinClock($app, $now);
        $browser = $this->signedIn($app);

        return [$app, $browser, $this->api($app, $this->apiKey($app, $this->owner($app)))];
    }

    /**
     * As start(), for an owner with their own display preferences.
     *
     * @return array{0: App<ContainerInterface>, 1: TestBrowser, 2: ApiClient}
     */
    protected function startWith(DisplayPreferences $preferences, string $now = self::NOW): array
    {
        $app = $this->createApp();
        $this->pinClock($app, $now);
        $this->resetDatabase($app);
        $owner = $this->createOwner($app, 'owner', $preferences);

        return [$app, $this->browserFor($app, 'owner'), $this->api($app, $this->apiKey($app, $owner))];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    protected function bought(
        App $app,
        string $purchased,
        string $price,
        ?string $sold = null,
        ?string $salePrice = null,
        string $make = 'Volkswagen',
        string $model = 'Golf',
        ?string $currency = null,
    ): Vehicle {
        $owner = $this->owner($app);

        return $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            $make,
            $model,
            FuelType::Petrol,
            registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
            currency: $currency,
            purchaseDate: self::day($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::day($sold),
            salePrice: $salePrice,
        ));
    }

    protected static function date(string $value): DateTimeImmutable
    {
        return LocalTime::parseDate($value) ?? throw new \LogicException($value);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function golden(string $scenario): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/golden/' . $scenario . '.json';
        $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

        return self::section($data);
    }

    /**
     * A golden section as a map.
     *
     * @return array<string, mixed>
     */
    protected static function section(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A golden list of strings.
     *
     * @return list<string>
     */
    protected static function strings(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<string> $value */
        return $value;
    }

    /**
     * The three readings of the vehicle's cost of ownership against the golden
     * file: the API (with its working), the CSV row and the page's text.
     *
     * @param array<string, mixed> $golden
     */
    protected function assertOwnershipAgrees(
        array $golden,
        TestBrowser $browser,
        ApiClient $api,
        Vehicle $vehicle,
    ): void {
        $expected = self::section($golden['ownership']);

        $response = $api->get('/vehicles/' . $vehicle->id . '/ownership?include=working');
        self::assertSame(200, $response->getStatusCode());
        $doc = ApiClient::json($response);
        foreach (self::section($expected['api']) as $path => $value) {
            self::assertSame($value, $doc->get(...explode('.', $path)), 'API ' . $path);
        }
        self::assertNotNull($doc->get('working', 'cost_of_ownership', 'steps'), 'the working is included');

        $csv = $browser->get('/reports/ownership.csv?vehicle=' . $vehicle->id);
        self::assertSame(200, $csv->getStatusCode());
        $rows = self::csvRows(self::body($csv));
        self::assertCount(2, $rows, 'a header and the one vehicle');
        $row = array_combine($rows[0], $rows[1]);
        foreach (self::section($expected['csv']) as $column => $value) {
            self::assertSame($value, $row[$column] ?? null, 'CSV ' . $column);
        }

        $page = self::body($browser->get('/vehicles/' . $vehicle->id . '/ownership'));
        $text = self::text($page);
        foreach (self::strings($expected['page']) as $needle) {
            self::assertStringContainsString($needle, $text, 'page');
        }

        // The three agree with each other, not only with the file (the CSV has 2 places, the API 3).
        self::assertSame(0, Decimal::compare($doc->string('total'), $row['Total']), 'API total and CSV total');
        self::assertSame(0, Decimal::compare($doc->string('running_costs'), $row['Running costs']), 'running costs');
        self::assertStringContainsString($doc->string('display', 'running_costs'), $text, 'page and API running costs');
    }

    /**
     * The true-cost API (since bought and each calendar year) and CSV against the
     * golden file's `true_cost`.
     *
     * @param array<string, mixed> $golden
     */
    protected function assertTrueCostAgrees(array $golden, TestBrowser $browser, ApiClient $api, Vehicle $vehicle): void
    {
        $expected = self::section($golden['true_cost']);
        $since = self::section($expected['since_bought']);
        /** @var list<array{label: string, csv_year: int, total: string, csv_total: string, parts: array<string, string>}> $expectedYears */
        $expectedYears = $expected['years'];

        $doc = ApiClient::json($api->get('/vehicles/' . $vehicle->id . '/true-cost?period=since_bought'));
        self::assertSame($since['total'], $doc->get('true_cost', 'total'), 'since bought total');
        foreach (self::section($since['parts']) as $part => $amount) {
            self::assertSame($amount, $doc->get('true_cost', 'parts', $part, 'amount'), 'since bought ' . $part);
        }

        $years = $doc->column('label', 'years');
        self::assertSame(array_column($expectedYears, 'label'), $years, 'the years');
        $csv = self::csvRows(self::body($browser->get('/reports/true-cost.csv?vehicle=' . $vehicle->id)));
        self::assertCount(count($expectedYears) + 1, $csv, 'a CSV row per year');
        foreach ($expectedYears as $i => $year) {
            self::assertSame($year['total'], $doc->get('years', $i, 'total'), $year['label'] . ' total');
            foreach ($year['parts'] as $part => $amount) {
                self::assertSame($amount, $doc->get('years', $i, 'parts', $part, 'amount'), $year['label'] . ' ' . $part);
            }
            $row = array_combine($csv[0], $csv[$i + 1]);
            self::assertSame((string) $year['csv_year'], $row['Year']);
            self::assertSame($year['csv_total'], $row['Total'], $year['label'] . ' CSV total');
        }
    }

    /**
     * The page's visible text, one space between words, entities decoded.
     */
    protected static function text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * @return list<list<string>>
     */
    protected static function csvRows(string $csv): array
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
