<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
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

        $widget = self::section(self::body($browser->get('/')), 'id="widget-true_cost"');

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
    private function car(App $app, string $make, string $model, string $purchased, string $price): Vehicle
    {
        return $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            $make,
            $model,
            FuelType::Petrol,
            registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
            purchaseDate: self::date($purchased),
            purchasePrice: $price,
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
