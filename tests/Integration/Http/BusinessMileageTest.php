<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\TripRepository;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Business mileage in Reports, cost per business mile and the dashboard
 * widget (Phase 22, spec.md §7.7, §7.8, §7.22).
 */
final class BusinessMileageTest extends AppTestCase
{
    use CostFixtures;

    private const string NOW = '2026-09-30T12:00:00Z';

    public function testReportsShowCostPerBusinessMileBesideTheClaimValue(): void
    {
        [$app, $browser, $golf] = $this->golf();
        // 1,000 mi driven for £120 of fuel: 12p a mile to run.
        $this->fillUp($app, $golf, '2026-06-01T08:00:00Z', '16093.440', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '17702.784', '40', '60.00');
        $this->trip($app, $golf, '2026-07-01', '160.934');

        $report = self::body($browser->get('/reports'));
        self::assertStringContainsString('Business mileage', $report);
        self::assertStringContainsString('100 mi', $report);
        self::assertStringContainsString('10%', $report, '100 of 1,000 mi');
        self::assertStringContainsString('£55.00', $report);
        self::assertStringContainsString('£0.55/mi', $report, 'the claim per business mile');
        self::assertStringContainsString('£0.12/mi', $report, 'what the car costs to run');
        self::assertStringContainsString('running costs only', $report, 'no value, so no depreciation');

        $claim = self::body($browser->get('/trips/claim'));
        self::assertStringContainsString('What the vehicles cost to run', $claim);
        self::assertStringContainsString('£0.12/mi', $claim);
    }

    public function testWithoutADistanceTheCostIsLeftOut(): void
    {
        [$app, $browser, $golf] = $this->golf();
        $this->trip($app, $golf, '2026-07-01', '160.934');

        $report = self::body($browser->get('/reports'));
        self::assertStringContainsString('Business mileage', $report);
        self::assertStringNotContainsString('/mi<br><span class="muted">running', $report, 'no cost per mile without distance or costs');
    }

    public function testAKilometreOwnerSeesPerKm(): void
    {
        $preset = UnitPreset::Metric;
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app, 'owner', new DisplayPreferences(
            'en_IE',
            'Europe/Dublin',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'EUR',
        ));
        $browser = $this->browserFor($app, 'owner');
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-06-01T08:00:00Z', '10000', '40', '60.00');
        $this->fillUp($app, $golf, '2026-09-01T08:00:00Z', '11000', '40', '60.00');
        $this->trip($app, $golf, '2026-07-01', '100');

        $report = self::body($browser->get('/reports'));
        self::assertStringContainsString('Cost per km', $report);
        self::assertStringContainsString('€0.12/km', $report);
    }

    public function testTheDashboardWidgetCountsDownToTheLowerRate(): void
    {
        [$app, $browser, $golf] = $this->golf();
        // 3,582 mi this tax year.
        $this->trip($app, $golf, '2026-05-01', '5764.670');

        $home = self::body($browser->get('/'));
        self::assertStringContainsString('Business mileage', $home);
        self::assertStringContainsString('3,582 mi', $home);
        self::assertStringContainsString('£1,970.10', $home, '3,582 mi at 55p');
        self::assertStringContainsString('6,418 mi until the £0.25 rate', $home);
        self::assertStringContainsString('href="/trips/claim"', $home);
    }

    public function testTheCommuteHintIsGbAndGerman(): void
    {
        [, $browser, $golf] = $this->golf();
        self::assertStringContainsString('commuting, not business mileage', self::body($browser->get('/vehicles/' . $golf->id . '/trips/new')));

        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $preset = UnitPreset::Metric;
        $this->createOwner($app, 'owner', new DisplayPreferences('de_DE', 'Europe/Berlin', $preset->distance(), $preset->volume(), $preset->consumption(), 'EUR'));
        $german = $this->browserFor($app, 'owner');
        $car = $this->vehicle($app);
        self::assertStringContainsString('erster Tätigkeitsstätte', self::body($german->get('/vehicles/' . $car->id . '/trips/new')));

        $this->resetDatabase($app);
        $this->createOwner($app, 'owner', new DisplayPreferences('en_US', 'America/New_York', $preset->distance(), $preset->volume(), $preset->consumption(), 'USD'));
        $american = $this->browserFor($app, 'owner');
        $car = $this->vehicle($app);
        self::assertStringNotContainsString('commuting', self::body($american->get('/vehicles/' . $car->id . '/trips/new')), 'the app never judges it elsewhere');
    }

    /**
     * @return array{0: App<ContainerInterface>, 1: TestBrowser, 2: Vehicle}
     */
    private function golf(): array
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);

        return [$app, $browser, $this->vehicle($app)];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function trip(App $app, Vehicle $vehicle, string $date, string $km): void
    {
        $day = LocalTime::parseDate($date);
        self::assertNotNull($day);
        $this->service($app, TripRepository::class)->insert($vehicle->id, new TripData(
            travelledOn: $day,
            fromPlace: 'Office',
            toPlace: 'Client site',
            distanceKm: $km,
            purpose: 'Site visit',
        ), new \DateTimeImmutable($date . 'T18:00:00Z'), $this->owner($app)->id);
    }
}
