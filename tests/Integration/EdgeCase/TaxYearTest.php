<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\EdgeCase;

use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\ClaimReport;
use Logbook\Service\Trip\ClaimReportService;
use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\TripService;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Phase 45.1.3, *A tax year spanning a rate change, and a trip on 5 and 6
 * April*; spec §8 *Money edge cases*, bullet **Tax years**, with §7.23
 * *Tax year* and *Valuing trips*.
 *
 * Read through the claim report service, as the claim page does. The owner
 * is a GB user, so the tax year runs 6 April to 5 April and HMRC's rates
 * (0.45 then 0.25 over 10,000 miles, 0.55 from 6 Apr 2026) are provided.
 * Distances are multiples of 125 miles (201.168 km), which convert exactly.
 */
final class TaxYearTest extends AppTestCase
{
    use CostFixtures;
    use EdgeCaseWorld;

    private const string NOW = '2026-10-05T10:00:00Z';

    public function testATripOn5AprilIsInTheOldTaxYearAndOneOn6AprilInTheNew(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2020-01-01', null);
        // 10,000 miles by September 2025 use up the cheaper band of 2025/26.
        $this->trip($app, $car, '2025-09-01', '16093.440');
        $this->trip($app, $car, '2026-04-05', '201.168');
        $this->trip($app, $car, '2026-04-06', '201.168');

        $claims = $this->service($app, ClaimReportService::class);
        self::assertSame('2025/26', $claims->taxYearOf($owner, self::day('2026-04-05'))->label());
        self::assertSame('2026/27', $claims->taxYearOf($owner, self::day('2026-04-06'))->label());

        $old = $this->claim($app, $owner, '2026-04-05');
        self::assertCount(2, $old->rows, 'the September trip and 5 April');
        self::assertSame('2026-04-05', $old->rows[1]->trip->data->travelledOn->format('Y-m-d'));
        self::assertSame('0.2500', $old->rows[1]->lines[0]->rate, 'past the 10,000 miles of 2025/26, at the old rate set');
        self::assertSame('31.25', $old->rows[1]->mileageAmount, '125 miles at 25p');
        self::assertSame('4531.25', $old->totals[0]->mileageAmount, '10,000 at 45p, then 125 at 25p');

        $new = $this->claim($app, $owner, '2026-04-06');
        self::assertCount(1, $new->rows, 'only 6 April');
        self::assertSame('2026-04-06', $new->rows[0]->trip->data->travelledOn->format('Y-m-d'));
        self::assertSame('0.5500', $new->rows[0]->lines[0]->rate, 'the band starts again, at the new rate set');
        self::assertSame('68.75', $new->rows[0]->mileageAmount, '125 miles at 55p');
        self::assertSame('68.75', $new->totals[0]->mileageAmount);
    }

    public function testARateChangeMidYearKeepsTheYearsRunningTotalForTheBand(): void
    {
        [$app, $owner] = $this->world(self::NOW);
        $car = $this->car($app, 'Golf', '2020-01-01', null);
        $rates = $this->service($app, MileageRateService::class);
        $rates->create($owner, $this->rates('2025-04-06', '0.4500', '0.2500'));
        $rates->create($owner, $this->rates('2025-10-01', '0.6000', '0.3000'));
        // 8,000 miles under the first set, then 4,000 under the second: 2,000 of the band left.
        $this->trip($app, $car, '2025-06-01', '12874.752');
        $this->trip($app, $car, '2025-11-01', '6437.376');

        $claims = $this->service($app, ClaimReportService::class);
        $report = $claims->build($owner, ClaimFilter::taxYear($claims->taxYearOf($owner, self::day('2025-11-01'))));

        self::assertSame('2025/26', $report->filter->taxYear?->label());
        self::assertCount(2, $report->rows);
        self::assertSame('3600.00', $report->rows[0]->mileageAmount, '8,000 miles at 45p');
        $crossing = $report->rows[1];
        self::assertTrue($crossing->isSplit(), 'the band runs out inside the second trip');
        self::assertSame(['2000.000', '2000.000'], array_map(static fn ($l): string => $l->distance, $crossing->lines));
        self::assertSame(['0.6000', '0.3000'], array_map(static fn ($l): string => $l->rate, $crossing->lines));
        self::assertSame(['1200.00', '600.00'], array_map(static fn ($l): string => $l->amount, $crossing->lines));
        self::assertSame('1800.00', $crossing->mileageAmount, 'not 4,000 at 60p: the 8,000 already counted');
        self::assertSame('5400.00', $report->totals[0]->mileageAmount);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function claim(App $app, User $owner, string $dayInYear): ClaimReport
    {
        $claims = $this->service($app, ClaimReportService::class);

        return $claims->build($owner, ClaimFilter::taxYear($claims->taxYearOf($owner, self::day($dayInYear))));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function trip(App $app, Vehicle $car, string $on, string $km): void
    {
        $this->service($app, TripService::class)->create($car, new TripData(
            self::day($on),
            'Office',
            'Client',
            distanceKm: $km,
            isBusiness: true,
            purpose: 'Visit',
        ));
    }

    private function rates(string $from, string $car, string $after): MileageRateSetData
    {
        return new MileageRateSetData(
            effectiveFrom: self::day($from),
            distanceUnit: DistanceUnit::Mile,
            currency: 'GBP',
            carRate: $car,
            carThreshold: '10000.000',
            carRateAfter: $after,
        );
    }
}
