<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Report\CurrencyReport;
use Logbook\Service\Report\ReportFilter;
use Logbook\Service\Report\ReportPeriod;
use Logbook\Service\Report\ReportRange;
use Logbook\Service\Report\ReportService;
use Logbook\Service\Report\TrueCostGap;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Report\VehicleTrueCost;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Number\Decimal;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Setup shared by the money edge case tests (Phase 45.1.3, spec §8 *Money
 * edge cases*): a booted app with the clock pinned and the owner signed up
 * (UK units, GBP, Europe/London), vehicles with a purchase, and the
 * figures read the way the pages read them. Used with CostFixtures, whose
 * `day()` it relies on.
 */
trait EdgeCaseWorld
{
    /**
     * @return array{0: App<ContainerInterface>, 1: User}
     */
    private function world(string $nowUtc): array
    {
        $app = $this->createApp();
        $this->pinClock($app, $nowUtc);
        $this->resetDatabase($app);

        return [$app, $this->createOwner($app)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function car(
        App $app,
        string $model,
        ?string $purchased,
        ?string $price,
        ?string $currency = null,
        FuelType $fuel = FuelType::Petrol,
        ?string $sold = null,
        ?string $soldFor = null,
    ): Vehicle {
        return $this->service($app, VehicleService::class)->create($this->owner($app), new VehicleData(
            VehicleType::Car,
            'Make',
            $model,
            $fuel,
            registration: strtoupper(substr($model, 0, 2)) . '19 ABC',
            currency: $currency,
            purchaseDate: $purchased === null ? null : self::day($purchased),
            purchasePrice: $price,
            saleDate: $sold === null ? null : self::day($sold),
            salePrice: $soldFor,
        ));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function value(App $app, Vehicle $vehicle, string $on, string $amount): void
    {
        $this->service($app, ValuationService::class)
            ->create($vehicle, new VehicleValuationData(self::day($on), $amount));
    }

    /**
     * A custom report over calendar dates, for one vehicle.
     *
     * @param App<ContainerInterface> $app
     */
    private function report(App $app, User $owner, Vehicle $vehicle, string $from, string $to): CurrencyReport
    {
        $filter = new ReportFilter(
            new ReportPeriod(ReportRange::Custom, self::day($from), self::day($to)),
            $vehicle->id,
            true,
        );
        $report = $this->service($app, ReportService::class)->build($owner, $filter);
        self::assertCount(1, $report->currencies);

        return $report->currencies[0];
    }

    /**
     * Cost of ownership and true cost as the pages read them, as of $today.
     *
     * @param App<ContainerInterface> $app
     */
    private function trueCost(App $app, User $owner, Vehicle $vehicle, string $today): VehicleTrueCost
    {
        $result = $this->service($app, TrueCostService::class)->forVehicle($owner, $vehicle, self::day($today));
        self::assertNotNull($result, 'the vehicle has an ownership period');

        return $result;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function shown(App $app, User $owner): DisplayFormatter
    {
        $this->service($app, DisplayContext::class)->apply($owner->preferences);

        return $this->service($app, DisplayFormatter::class);
    }

    private static function assertDecimal(string $expected, ?string $actual, string $message = ''): void
    {
        self::assertNotNull($actual, $message . ' (nothing shown)');
        self::assertSame(0, Decimal::compare($expected, $actual), $message . " expected {$expected}, got {$actual}");
    }

    /**
     * Reports, cost of ownership and true cost have a cost, a purchase day
     * reading or none, 90 days and a purchase date: only the distance is
     * missing, and each says so.
     *
     * @param App<ContainerInterface> $app
     */
    private function assertNoPerDistance(App $app, User $owner, Vehicle $car, string $today, int $costs = 1): void
    {
        $report = $this->report($app, $owner, $car, '2026-01-01', $today);
        self::assertSame($costs, $report->count, 'there is a cost to divide');
        self::assertNull($report->distanceKm);
        self::assertNull($report->costPerKm, 'Reports shows "—"');
        self::assertSame('', $this->shown($app, $owner)->perDistance($report->costPerKm, 'GBP'));

        $result = $this->trueCost($app, $owner, $car, $today);
        self::assertSame($costs, $result->ownership->count);
        self::assertNull($result->ownership->distanceKm);
        self::assertNull($result->ownership->perKm, 'cost of ownership shows "—"');
        self::assertNull($result->ownership->runningPerKm);
        self::assertSame(TrueCostGap::NoMileage, $result->sinceBought->gap, 'true cost: not enough mileage');
        self::assertNull($result->sinceBought->perKm);
        self::assertNotNull($result->lastTwelveMonths);
        self::assertSame(TrueCostGap::NoMileage, $result->lastTwelveMonths->gap);
    }

    /**
     * What a page says: its text with the markup stripped and the spaces
     * collapsed, so a figure can be looked for as a user reads it. The true
     * cost pages write a minus as U+2212 and the other pages as a hyphen;
     * both read "-" here (the spec's prose uses U+2212 but does not say which
     * glyph the page must use).
     */
    private function pageText(TestBrowser $browser, string $url): string
    {
        $text = preg_replace('/\s+/', ' ', str_replace("\u{2212}", '-', strip_tags(self::body($browser->get($url)))));
        self::assertIsString($text);

        return trim($text);
    }
}
