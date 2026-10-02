<?php

declare(strict_types=1);

namespace Logbook\Service\Station;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Add/edit station form ↔ StationData (spec.md §6 Station, §7.33).
 */
final class StationForm
{
    /**
     * @return array<string, string|list<string>>
     */
    public static function values(Station $station): array
    {
        $data = $station->data;

        return [
            'name' => $data->name,
            'brand' => $data->brand ?? '',
            'address' => $data->address ?? '',
            'postcode' => $data->postcode ?? '',
            'country' => $data->country ?? '',
            'latitude' => $data->latitude === null ? '' : Decimal::trim($data->latitude),
            'longitude' => $data->longitude === null ? '' : Decimal::trim($data->longitude),
            'grades' => array_map(static fn (FuelGrade $grade): string => $grade->value, $data->grades),
            'opening_hours' => $data->openingHours ?? '',
            'notes' => $data->notes ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, string $locale): StationData|ValidationErrors
    {
        $validator = new Validator($input, $locale);
        $name = $validator->string('name', true, 100);
        $brand = $validator->string('brand', false, 50);
        $address = $validator->string('address', false, 200);
        $postcode = $validator->string('postcode', false, 20);
        $country = self::country($validator);
        [$latitude, $longitude] = Coordinates::parse($validator, false);
        $grades = self::grades($validator, $input['grades'] ?? []);
        $hours = $validator->string('opening_hours', false, 200);
        $notes = $validator->string('notes', false, 1000);

        if ($name !== null && StationName::tidy($name) === '') {
            $validator->addError('name', 'validation.required');
        }
        if (!$validator->errors()->isEmpty() || $name === null) {
            return $validator->errors();
        }

        return new StationData(
            name: StationName::tidy($name),
            brand: $brand,
            address: $address,
            postcode: $postcode === null ? null : strtoupper(StationName::tidy($postcode)),
            country: $country,
            latitude: $latitude,
            longitude: $longitude,
            grades: $grades,
            openingHours: $hours,
            notes: $notes,
        );
    }

    private static function country(Validator $validator): ?string
    {
        $raw = strtoupper(trim($validator->raw('country')));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^[A-Z]{2}$/', $raw) !== 1) {
            $validator->addError('country', 'stations.validation.country');

            return null;
        }

        return $raw;
    }

    /**
     * @return list<FuelGrade> in the enum's order, each once
     */
    private static function grades(Validator $validator, mixed $raw): array
    {
        $codes = is_array($raw) ? $raw : ($raw === '' || $raw === null ? [] : [$raw]);
        $chosen = [];
        foreach ($codes as $code) {
            $grade = is_string($code) ? FuelGrade::tryFrom($code) : null;
            // Home charging is never a station's (spec.md §7.33, #131).
            if ($grade === null || $grade === FuelGrade::Home) {
                $validator->addError('grades', 'validation.choice');

                return [];
            }
            $chosen[$grade->value] = true;
        }

        return array_values(array_filter(
            FuelGrade::cases(),
            static fn (FuelGrade $grade): bool => isset($chosen[$grade->value]),
        ));
    }
}
