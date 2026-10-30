<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * True cost on the pages (docs/phases/phase-32.md): the overview card's
 * breakdown and switch, the dashboard widget's ranking, the Reports tab
 * with What changed and its CSV, the Expenses tab card, and a switched-off
 * module's part left out. UK units and GBP; "today" is 5 Oct 2026.
 */
final class TrueCostPagesTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-10-05T10:00:00Z';

    public function testTheOverviewCardSplitsPerDistanceIntoItsParts(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);

        $card = self::section(self::body($browser->get('/vehicles/' . $golf->id)), 'aria-labelledby="cost-heading"');

        self::assertStringContainsString('What it is made of', $card);
        self::assertStringContainsString('href="/vehicles/' . $golf->id . '?true_cost=last_12_months#true-cost"', $card);
        self::assertMatchesRegularExpression('/aria-current="true">Since bought</', $card);
        foreach (['Fuel', 'Insurance, tax and MOT', 'Depreciation'] as $part) {
            self::assertStringContainsString('<span class="legend__label">' . $part . '</span>', $card);
        }
        self::assertStringContainsString('href="/reports/true-cost?vehicle=' . $golf->id . '"', $card);

        $twelve = self::section(
            self::body($browser->get('/vehicles/' . $golf->id . '?true_cost=last_12_months')),
            'aria-labelledby="cost-heading"',
        );
        self::assertMatchesRegularExpression('/aria-current="true">Last 12 months</', $twelve);
    }

    public function testTheWidgetRanksVehiclesAndFollowsTheChip(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $polo = $this->car($app, 'Volkswagen', 'Polo', '2024-01-01', '9000');
        $this->reading($app, $polo, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($app, $polo, '2025-11-15T09:00:00Z', '20000', '200', '4900.00');
        $unlogged = $this->car($app, 'Skoda', 'Fabia', '2024-01-01', '8000');
        $this->expense($app, $unlogged, '2026-05-01', '40');
        // Dearer per km than all of them, but in euros: its own ranking, never converted.
        $euro = $this->car($app, 'Renault', 'Clio', '2024-01-01', '9000', 'EUR');
        $this->reading($app, $euro, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($app, $euro, '2025-11-15T09:00:00Z', '3000', '200', '9000.00');

        $widget = self::section(self::body($browser->get('/')), 'id="widget-true_cost"');
        $lists = explode('<ol class="true-cost__ranking">', $widget);
        self::assertCount(3, $lists, 'one ranking per currency');
        self::assertStringContainsString('Volkswagen Golf', $lists[1], 'pounds first: more vehicles');
        self::assertStringContainsString('Renault Clio', $lists[2]);
        self::assertStringContainsString('€', $lists[2]);

        $polo = strpos($widget, 'Volkswagen Polo');
        $golfAt = strpos($widget, 'Volkswagen Golf');
        $fabia = strpos($widget, 'Skoda Fabia');
        self::assertNotFalse($polo);
        self::assertNotFalse($golfAt);
        self::assertNotFalse($fabia);
        self::assertTrue($polo < $golfAt, 'the dearer per mile first');
        self::assertTrue($golfAt < $fabia, 'no figure last');
        self::assertStringContainsString('Not enough mileage logged', $widget);
        self::assertMatchesRegularExpression('/true-cost__change[^>]*>[↑↓] £/u', $widget, 'the change on the 12 months before');

        $chip = self::section(self::body($browser->get('/?vehicle=' . $golf->id)), 'id="widget-true_cost"');
        self::assertStringContainsString('Volkswagen Golf', $chip);
        self::assertStringNotContainsString('Skoda Fabia', $chip);
        self::assertStringContainsString('href="/?vehicle=' . $golf->id . '&amp;true_cost=since_bought#widget-true_cost"', $chip);
    }

    public function testTheReportShowsTheTrendAndWhatChangedWithItsCsv(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);

        $report = self::body($browser->get('/reports/true-cost'));
        self::assertStringContainsString('What changed: 2025', $report);
        self::assertStringContainsString('because you drove', $report);
        self::assertStringContainsString('2026 so far', $report);
        self::assertStringContainsString('data-chart=', $report);

        $csv = $browser->get('/reports/true-cost.csv?vehicle=' . $golf->id);
        self::assertSame(200, $csv->getStatusCode());
        $body = self::body($csv);
        self::assertStringContainsString('Vehicle,Registration,Currency,Year,Partial year,Distance driven (Miles)', $body);
        self::assertStringContainsString('Volkswagen Golf,GO19 ABC,GBP,2025,no', $body);

        $expenses = self::section(
            self::body($browser->get('/vehicles/' . $golf->id . '/expenses')),
            'aria-labelledby="true-cost-heading"',
        );
        self::assertStringContainsString('True cost by year', $expenses);
    }

    public function testTheCoverNoteShowsOnceUnderTheTableForAVehicleOlderThanThreeYears(): void
    {
        // Bought in 2021, so 2021 is not among the three years with a drawn working (K152, #568).
        $build = function (App $app): void {
            $car = $this->car($app, 'Volkswagen', 'Golf', '2021-07-01', '15000');
            $this->reading($app, $car, '1000', '2021-07-01T09:00:00Z');
            $this->fillUp($app, $car, '2026-10-01T09:00:00Z', '25000', '400', '600.00');
            // A year's policy from 1 January: 181 of its 365 days fall before the purchase.
            $this->document($app, $car, ComplianceType::Insurance, '2021-01-01', '2021-12-31', '365.00');
        };

        $app = $this->createApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $build($app);
        $report = self::body($browser->get('/reports/true-cost'));
        self::assertSame(
            1,
            substr_count($report, 'of cover before you bought it isn’t counted, so the years can differ from Since bought.'),
        );
        self::assertStringContainsString('£181.00 of cover before you bought it', $report);

        [$german, $browser] = $this->germanOwner();
        $build($german);
        $report = self::body($browser->get('/reports/true-cost'));
        self::assertSame(1, substr_count($report, 'für die Zeit vor dem Kauf werden nicht gezählt, daher können die Jahre'));
    }

    public function testTheCoverNoteShowsForAPurchaseYearAmongTheLatestThreeWithNoWorking(): void
    {
        // Bought 1 Mar 2026; the only policy ended 28 Feb 2026, wholly before the purchase,
        // so 2026 has no costs and no working (K171).
        $build = function (App $app): void {
            $car = $this->car($app, 'Volkswagen', 'Golf', '2026-03-01', '15000');
            $this->reading($app, $car, '1000', '2026-03-01T09:00:00Z');
            $this->document($app, $car, ComplianceType::Insurance, '2025-03-01', '2026-02-28', '365.00');
        };

        $english = $this->start();
        $build($english[0]);
        $report = self::body($english[1]->get('/reports/true-cost'));
        self::assertStringContainsString('£365.00 of cover before you bought it', $report);
        self::assertSame(1, substr_count($report, 'of cover before you bought it'));

        [$german, $browser] = $this->germanOwner();
        $build($german);
        $report = self::body($browser->get('/reports/true-cost'));
        self::assertStringContainsString('für die Zeit vor dem Kauf werden nicht gezählt', $report);
    }

    public function testTheCoverNoteShowsForAYoungVehicleWithAWorkingAndAfterASale(): void
    {
        // Bought 1 Jan 2025, sold 31 Mar 2026: a policy from 1 Jul 2025 leaves 91 days after the sale out,
        // and a 2024 one is wholly before the purchase (K173).
        [$app, $browser] = $this->start();
        $car = $this->car($app, 'Volkswagen', 'Golf', '2025-01-01', '15000', null, '2026-03-31', '12000');
        $this->reading($app, $car, '1000', '2025-01-01T09:00:00Z');
        $this->document($app, $car, ComplianceType::Insurance, '2025-07-01', '2026-06-30', '365.00');
        $this->document($app, $car, ComplianceType::Insurance, '2024-01-01', '2024-12-31', '366.00');

        $report = self::body($browser->get('/reports/true-cost'));

        self::assertStringContainsString('of cover after you sold it', $report);
        self::assertStringContainsString('of cover before you bought it', $report);
        self::assertStringContainsString('£91.00 of cover after you sold it', $report);
        // Once in the sale year's working and once under the yearly table, which no longer skips the latest three years.
        self::assertSame(2, substr_count($report, 'of cover after you sold it'));
    }

    public function testASwitchedOffModuleLeavesItsPartOut(): void
    {
        [$app, $browser] = $this->start();
        $golf = $this->golf($app);
        $this->service($app, FeatureToggles::class)->save(array_values(array_filter(
            Feature::cases(),
            static fn (Feature $f): bool => $f !== Feature::Compliance,
        )));

        $card = self::section(self::body($browser->get('/vehicles/' . $golf->id)), 'aria-labelledby="cost-heading"');

        self::assertStringNotContainsString('Insurance, tax and MOT', $card);
        self::assertStringContainsString('<span class="legend__label">Fuel</span>', $card);
    }

    /**
     * Bought £15,000 on 1 Jan 2024, valued each January; 10,000 km in 2024,
     * 8,000 in 2025, 6,000 this year; insurance each March.
     *
     * @param App<ContainerInterface> $app
     */
    private function golf(App $app): Vehicle
    {
        $golf = $this->car($app, 'Volkswagen', 'Golf', '2024-01-01', '15000');
        $this->reading($app, $golf, '1000', '2024-01-01T09:00:00Z');
        $this->fillUp($app, $golf, '2024-12-31T09:00:00Z', '11000', '700', '1050.00');
        $this->fillUp($app, $golf, '2025-12-31T09:00:00Z', '19000', '540', '800.00');
        $this->fillUp($app, $golf, '2026-10-01T09:00:00Z', '25000', '400', '600.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2024-03-01', null, '500.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2025-03-01', null, '520.00');
        $this->document($app, $golf, ComplianceType::Insurance, '2026-03-01', null, '540.00');
        $valuations = $this->service($app, ValuationService::class);
        $valuations->create($golf, new VehicleValuationData(self::date('2025-01-01'), '12000'));
        $valuations->create($golf, new VehicleValuationData(self::date('2026-01-01'), '9800'));

        return $golf;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function car(
        App $app,
        string $make,
        string $model,
        string $purchased,
        string $price,
        ?string $currency = null,
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
            purchaseDate: self::date($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::date($sold),
            salePrice: $salePrice,
        ));
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser}
     */
    private function germanOwner(): array
    {
        $german = $this->createApp();
        $this->pinClock($german, self::NOW);
        $this->resetDatabase($german);
        $preset = UnitPreset::Metric;
        $this->createOwner($german, 'owner', new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        ));

        return [$german, $this->browserFor($german, 'owner')];
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

    private static function section(string $html, string $marker): string
    {
        $at = strpos($html, $marker);
        self::assertNotFalse($at, $marker);
        $end = strpos($html, '</section>', $at);

        return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = LocalTime::parseDate($value);
        self::assertNotNull($date);

        return $date;
    }
}
