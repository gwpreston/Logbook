<?php

declare(strict_types=1);

namespace Logbook\Support\Geo;

/**
 * Great-circle ("in a straight line") distances between two positions
 * (spec.md §7.33). Road distance needs a routing service; this never does.
 * A displayed figure, not stored, so floats are fine here.
 */
final class Haversine
{
    /** The mean Earth radius (IUGG), in kilometres. */
    public const float EARTH_RADIUS_KM = 6371.0088;

    public static function km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * The latitude and longitude half-widths, in degrees, of a box holding
     * every point within $km of a latitude: the cheap first pass of a
     * nearby search, refined by km().
     *
     * @return array{0: float, 1: float}
     */
    public static function box(float $lat, float $km): array
    {
        $radius = $km / self::EARTH_RADIUS_KM;
        $dLat = rad2deg($radius);
        // The widest longitude reached at that great-circle radius (not the
        // parallel's: a great circle bends poleward, so it reaches further).
        $sin = sin($radius) / cos(deg2rad($lat));
        $dLon = abs($sin) >= 1.0 || !is_finite($sin) ? 180.0 : min(180.0, rad2deg(asin(abs($sin))));

        return [$dLat, $dLon];
    }
}
