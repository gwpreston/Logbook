<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Tests\Support\JsonDoc;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * `trips_summary` (spec.md §7.26): business trips and their claim value.
 */
final class TripsSummaryTest extends ToolsBTestCase
{
    private const array TRIPS = ['FEATURES_TRIPS' => 'true'];

    public function testTheTaxYearsClaimMatchesTheClaimReport(): void
    {
        [$app, $owner] = $this->askApp(self::TRIPS);
        $golf = $this->vehicle($app);
        $this->service($app, MileageRateService::class)->forUser($owner);
        $this->trip($app, $golf, '2026-05-10', '90.5');
        $this->trip($app, $golf, '2026-09-02', '40');
        $this->trip($app, $golf, '2026-03-01', '25', 'Last tax year');
        $this->assertSchemaAccepts($app, $owner, 'trips_summary', ['period' => 'tax_year']);

        $result = $this->toolResult($app, $owner, 'trips_summary');
        $data = new JsonDoc($result->data);
        $claims = $this->service($app, ClaimReportService::class);
        $report = $claims->build($owner, ClaimFilter::taxYear($claims->taxYearOf($owner, new DateTimeImmutable('2026-10-15'))));

        self::assertSame('2026-04-06', $data->get('period', 'from'));
        self::assertSame('2027-04-05', $data->get('period', 'to'));
        self::assertSame(2, $data->int('business_trips'));
        self::assertSame($report->distanceKm(), $data->get('business_distance', 'km'));
        self::assertSame('81 mi', $data->get('business_distance', 'display'));
        $totals = $report->totals[0];
        self::assertSame('GBP', $data->get('claim', 0, 'currency'));
        self::assertSame(
            $this->service($app, DisplayFormatter::class)->money($totals->approvedAmount(), 'GBP'),
            $data->get('claim', 0, 'approved_total', 'display'),
        );
        self::assertSame('/trips/claim?year=2026', $result->link);
        self::assertStringStartsWith('Trips · tax year ', $result->source);
    }

    public function testACustomPeriodLinksToACustomClaim(): void
    {
        [$app, $owner] = $this->askApp(self::TRIPS);
        $golf = $this->vehicle($app);
        $this->trip($app, $golf, '2026-03-01', '25');

        $result = $this->toolResult($app, $owner, 'trips_summary', ['from' => '2026-01-01', 'to' => '2026-03-31']);

        self::assertSame(1, $result->data['business_trips']);
        self::assertSame('/trips/claim?period=custom&from=2026-01-01&to=2026-03-31', $result->link);
    }

    public function testTripsOffHidesTheTool(): void
    {
        [$app, $owner] = $this->askApp();

        self::assertFalse($this->isOffered($app, $owner, 'trips_summary'), 'trips are off by default');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function trip(App $app, Vehicle $vehicle, string $on, string $km, string $purpose = 'Client visit'): void
    {
        $this->service($app, TripService::class)->create($vehicle, new TripData(
            new DateTimeImmutable($on, new DateTimeZone('UTC')),
            'Ballymena',
            'Belfast',
            false,
            $km,
            purpose: $purpose,
        ));
    }
}
