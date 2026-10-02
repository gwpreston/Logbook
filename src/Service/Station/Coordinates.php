<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\Validator;

/**
 * Latitude and longitude fields (spec.md §7.33 *Positions*). Read with a
 * point, as the browser's geolocation and every map show them, whatever
 * the locale; a lone comma is taken as the point. Stored with 6 places
 * (about 0.1 m).
 */
final class Coordinates
{
    public const int SCALE = 6;

    /**
     * Both or neither; with $required, both.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parse(Validator $validator, bool $required, string $lat = 'latitude', string $lon = 'longitude'): array
    {
        $latitude = self::one($validator, $lat, '90');
        $longitude = self::one($validator, $lon, '180');
        $errors = $validator->errors();
        if ($errors->has($lat) || $errors->has($lon)) {
            return [null, null];
        }
        if ($latitude === null && $longitude === null) {
            if ($required) {
                $validator->addError($lat, 'validation.required');
            }

            return [null, null];
        }
        if ($latitude === null || $longitude === null) {
            $validator->addError($latitude === null ? $lat : $lon, 'stations.validation.both_coordinates');

            return [null, null];
        }

        return [$latitude, $longitude];
    }

    private static function one(Validator $validator, string $field, string $limit): ?string
    {
        $raw = trim($validator->raw($field));
        if ($raw === '') {
            return null;
        }
        if (!str_contains($raw, '.') && substr_count($raw, ',') === 1) {
            $raw = str_replace(',', '.', $raw);
        }
        $raw = str_replace(['−', '–'], '-', $raw);
        if (preg_match('/^-?\d{1,3}(\.\d+)?$/', $raw) !== 1) {
            $validator->addError($field, 'stations.validation.coordinate');

            return null;
        }
        $value = Decimal::round($raw, self::SCALE);
        $magnitude = ltrim($value, '-');
        if (Decimal::compare($magnitude, $limit) > 0) {
            $validator->addError($field, 'stations.validation.coordinate_range', ['max' => $limit]);

            return null;
        }

        return $value;
    }
}
