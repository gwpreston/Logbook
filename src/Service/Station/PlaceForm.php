<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Station\Place;
use Logbook\Domain\Station\PlaceData;
use Logbook\Domain\Station\StationName;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit place form ↔ PlaceData (spec.md §6 Place, §7.33 *Places*).
 */
final class PlaceForm
{
    /**
     * @return array<string, string>
     */
    public static function values(Place $place): array
    {
        return [
            'name' => $place->data->name,
            'latitude' => Decimal::trim($place->data->latitude),
            'longitude' => Decimal::trim($place->data->longitude),
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, string $locale): PlaceData|ValidationErrors
    {
        $validator = new Validator($input, $locale);
        $name = $validator->string('name', true, 50);
        [$latitude, $longitude] = Coordinates::parse($validator, true);
        if ($name !== null && StationName::tidy($name) === '') {
            $validator->addError('name', 'validation.required');
        }
        if (!$validator->errors()->isEmpty() || $name === null || $latitude === null || $longitude === null) {
            return $validator->errors();
        }

        return new PlaceData(StationName::tidy($name), $latitude, $longitude);
    }
}
