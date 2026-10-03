<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * Where *Cheapest near me* searches from (spec.md §7.34 *From*): the
 * browser's current position (used for the search only, never stored or
 * logged), one of the user's places, or a station.
 */
final readonly class NearOrigin
{
    public const string HERE = 'here';
    public const string PLACE = 'place';
    public const string STATION = 'station';

    private function __construct(
        public string $kind,
        public float $latitude,
        public float $longitude,
        public ?string $label = null,
        public ?int $id = null,
    ) {
    }

    public static function here(float $latitude, float $longitude): self
    {
        return new self(self::HERE, $latitude, $longitude);
    }

    public static function place(int $id, string $name, float $latitude, float $longitude): self
    {
        return new self(self::PLACE, $latitude, $longitude, $name, $id);
    }

    public static function station(int $id, string $name, float $latitude, float $longitude): self
    {
        return new self(self::STATION, $latitude, $longitude, $name, $id);
    }

    /**
     * A position typed or sent by a browser: both finite and in range.
     */
    public static function validPosition(mixed $latitude, mixed $longitude): ?self
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        $lat = (float) $latitude;
        $lon = (float) $longitude;
        if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90.0 || abs($lon) > 180.0) {
            return null;
        }

        return self::here($lat, $lon);
    }
}
