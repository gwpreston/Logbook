<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Report;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Report\TrueCostService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * A true-cost year under 90 days owned has no per-distance figure, as *Last
 * 12 months* (spec.md §7.35, #410, decided 2026-10-12): the golden Golf's
 * 2023 is 86 days. Its amounts still show.
 */
final class ShortYearTrueCostTest extends AppTestCase
{
    use CostFixtures;

    public function testAYearUnderNinetyDaysOwnedHasNoRate(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2024-12-31T12:00:00Z');
        $this->signedIn($app);
        $owner = $this->owner($app);
        $golf = $this->service($app, VehicleService::class)->create($owner, new VehicleData(
            VehicleType::Car,
            'Volkswagen',
            'Golf',
            FuelType::Petrol,
            purchaseDate: LocalTime::parseDate('2023-10-07'),
            purchasePrice: '17995',
        ));
        $this->fillUp($app, $golf, '2023-10-07T10:00:00Z', '40000', '40', '60.00');
        $this->fillUp($app, $golf, '2023-12-20T10:00:00Z', '43000', '40', '60.00');
        $this->fillUp($app, $golf, '2024-06-20T10:00:00Z', '50000', '40', '60.00');
        $this->fillUp($app, $golf, '2024-12-20T10:00:00Z', '57000', '40', '60.00');

        $result = $this->service($app, TrueCostService::class)->forVehicle($owner, $golf, new DateTimeImmutable('2024-12-31'));
        self::assertNotNull($result);
        [$first, $second] = $result->years;

        self::assertSame(2023, $first->period->year);
        self::assertSame('120.000', $first->total()->toDecimal(3), 'the amounts still show');
        self::assertNull($first->perKm, '7 Oct to 31 Dec is 86 days: no rate');
        self::assertSame(2024, $second->period->year);
        self::assertNotNull($second->perKm, 'a whole year has its rate');
        self::assertArrayNotHasKey(2024, $result->changes, 'no rate in 2023, so nothing to compare 2024 with');
    }
}
