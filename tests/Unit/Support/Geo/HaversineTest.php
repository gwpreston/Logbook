<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\Geo;

use Logbook\Support\Geo\Haversine;
use Logbook\Support\Units\DistanceUnit;
use PHPUnit\Framework\TestCase;

/**
 * Straight-line distances (spec.md §7.33 *Distances*).
 */
final class HaversineTest extends TestCase
{
    public function testLondonToEdinburghInKilometresAndMiles(): void
    {
        // Charing Cross to Edinburgh Waverley: about 534 km (332 mi) as the crow flies.
        $km = Haversine::km(51.5074, -0.1278, 55.9533, -3.1883);
        self::assertEqualsWithDelta(533.65, $km, 0.05);
        self::assertEqualsWithDelta(331.6, $km / DistanceUnit::KM_PER_MILE, 0.1);
    }

    public function testTwoPointsOneKilometreApart(): void
    {
        $north = 54.0 + rad2deg(1 / Haversine::EARTH_RADIUS_KM);
        self::assertEqualsWithDelta(1.0, Haversine::km(54.0, -6.0, $north, -6.0), 1e-9);
        self::assertEqualsWithDelta(0.6214, Haversine::km(54.0, -6.0, $north, -6.0) / DistanceUnit::KM_PER_MILE, 1e-4);
        self::assertSame(0.0, Haversine::km(54.0, -6.0, 54.0, -6.0));
    }

    public function testTheBoxHoldsEveryPointWithinTheDistance(): void
    {
        [$dLat, $dLon] = Haversine::box(54.0, 10.0);
        self::assertEqualsWithDelta(10.0, Haversine::km(54.0, -6.0, 54.0 + $dLat, -6.0), 1e-6);
        // The box's edge at that latitude is never closer than the distance.
        self::assertGreaterThanOrEqual(10.0 - 1e-9, Haversine::km(54.0, -6.0, 54.0, -6.0 + $dLon));
        // ... and the farthest point east within the distance is inside it.
        $farthest = rad2deg(asin(sin(10.0 / Haversine::EARTH_RADIUS_KM) / cos(deg2rad(54.0))));
        self::assertLessThanOrEqual($dLon + 1e-12, $farthest);
        self::assertSame(180.0, Haversine::box(90.0, 10.0)[1], 'at a pole every longitude is close');
    }
}
