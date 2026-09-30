<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use DateTimeImmutable;
use JsonException;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;

/**
 * JSON request bodies → the form input the fill-up and reading forms parse
 * (spec.md §7.20). A new input adapter, like the CSV import's: the forms'
 * parsers, validation and messages are unchanged.
 *
 * - Numbers are never floats: number tokens are turned into strings before
 *   decoding, and a decimal must be plain ("61.32", not "6.132e1" or
 *   "61,32"), read with "." as the decimal point.
 * - Instants carry a zone and are taken to the minute, as the forms keep
 *   them; they reach the form as UTC wall-clock time.
 * - Units are the request's own (or the owner's): they go into the
 *   preferences the form converts with, so the form does the converting.
 */
final class JsonInput
{
    private const string NUMBER = '/^-?[0-9]+(\.[0-9]+)?$/';
    private const string LOCALE = 'en';

    /** API field → form field, for the fill-up form. */
    public const array FUEL_FIELDS = [
        'filled_at' => 'filled_at',
        'odometer' => 'odometer',
        'fuel' => 'fuel',
        'grade' => 'fuel',
        'volume' => 'volume',
        'price_per_unit' => 'price',
        'total_cost' => 'total',
        'is_partial' => 'partial',
        'is_missed_previous' => 'missed_previous',
        'station' => 'station',
        'notes' => 'notes',
    ];
    private const array FUEL_EXTRA = ['distance_unit', 'volume_unit'];

    /** API field → form field, for the odometer reading form. */
    public const array READING_FIELDS = [
        'recorded_at' => 'recorded_at',
        'odometer' => 'reading',
        'note' => 'note',
    ];
    private const array READING_EXTRA = ['distance_unit'];

    /**
     * The body as an object, numbers as strings.
     *
     * @return array<string, mixed>
     * @throws ApiProblem when it is not a JSON object
     */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        // Outside strings, wrap every number token in quotes. Strings are matched
        // first and whole, so digits inside them are never touched.
        $quoted = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            static fn (array $m): string => $m[0][0] === '"' ? $m[0] : '"' . $m[0] . '"',
            $body,
        );
        try {
            $data = json_decode((string) $quoted, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiProblem(400, 'invalid_body', 'The request body must be a JSON object.');
        }

        $object = [];
        foreach ($data as $name => $value) {
            $object[(string) $name] = $value;
        }

        return $object;
    }

    /**
     * A fill-up body as the fill-up form's input, and the preferences to
     * parse it with.
     *
     * @param array<string, mixed> $body
     * @param string $defaultChoice the form's fuel picker default ("petrol" or "petrol:e10")
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function fuel(
        array $body,
        DisplayPreferences $owner,
        string $defaultChoice,
        DateTimeImmutable $now,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::FUEL_FIELDS), ...self::FUEL_EXTRA], $errors);

        $input = [
            'filled_at' => self::instant($body, 'filled_at', $now, $errors),
            'odometer' => self::decimal($body, 'odometer', $errors),
            'volume' => self::decimal($body, 'volume', $errors),
            'price' => self::decimal($body, 'price_per_unit', $errors),
            'total' => self::decimal($body, 'total_cost', $errors),
            'partial' => self::flag($body, 'is_partial', $errors),
            'missed_previous' => self::flag($body, 'is_missed_previous', $errors),
            'station' => self::text($body, 'station', $errors),
            'notes' => self::text($body, 'notes', $errors),
        ];

        $fuelCode = self::text($body, 'fuel', $errors);
        $gradeCode = self::text($body, 'grade', $errors);
        if ($fuelCode === '' && $gradeCode === '') {
            $input['fuel'] = $defaultChoice;
        } else {
            // A grade alone names its family.
            $input['fuel'] = $fuelCode !== '' ? $fuelCode : (FuelGrade::tryFrom($gradeCode)?->family()->value ?? $gradeCode);
            $input['grade'] = $gradeCode;
        }
        $fuel = Fuel::tryFrom(explode(':', $input['fuel'], 2)[0]);

        $distance = self::distanceUnit($body, $owner, $errors);
        $volume = $owner->volumeUnit;
        $unit = self::text($body, 'volume_unit', $errors);
        if ($unit === 'kwh') {
            if ($fuel !== null && !$fuel->isElectric()) {
                $errors->add('volume_unit', 'api.validation.kwh_for_electric');
            }
        } elseif ($unit !== '') {
            $volume = VolumeUnit::tryFrom($unit) ?? $volume;
            if (VolumeUnit::tryFrom($unit) === null) {
                $errors->add('volume_unit', 'validation.choice');
            } elseif ($fuel !== null && $fuel->isElectric()) {
                $errors->add('volume_unit', 'api.validation.electric_in_kwh');
            }
        }

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $volume)]
            : $errors;
    }

    /**
     * An odometer reading body as the reading form's input.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function reading(array $body, DisplayPreferences $owner, DateTimeImmutable $now): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::READING_FIELDS), ...self::READING_EXTRA], $errors);

        $input = [
            'recorded_at' => self::instant($body, 'recorded_at', $now, $errors),
            'reading' => self::decimal($body, 'odometer', $errors),
            'note' => self::text($body, 'note', $errors),
        ];
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A form's errors under the API's field names.
     *
     * @param array<string, string> $fields API field → form field
     */
    public static function renamed(ValidationErrors $formErrors, array $fields): ValidationErrors
    {
        $byForm = [];
        foreach ($fields as $api => $form) {
            $byForm[$form] ??= $api;
        }

        $errors = new ValidationErrors();
        foreach ($formErrors->all() as $field => $error) {
            $errors->add($byForm[$field] ?? $field, $error['key'], $error['params']);
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $known
     */
    private static function unknownFields(array $body, array $known, ValidationErrors $errors): void
    {
        foreach (array_keys($body) as $name) {
            if (!in_array($name, $known, true)) {
                $errors->add($name, 'api.validation.unknown_field');
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function instant(array $body, string $name, DateTimeImmutable $now, ValidationErrors $errors): string
    {
        $value = $body[$name] ?? null;
        if ($value === null) {
            return self::wallClock($now);
        }
        $instant = is_string($value) ? ListQuery::instant($value) : null;
        if ($instant === null) {
            $errors->add($name, 'api.validation.instant');

            return '';
        }

        return self::wallClock($instant);
    }

    /**
     * UTC wall-clock time to the minute, as a datetime-local input sends it.
     */
    private static function wallClock(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i');
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function decimal(array $body, string $name, ValidationErrors $errors): string
    {
        $value = $body[$name] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || preg_match(self::NUMBER, trim($value)) !== 1) {
            $errors->add($name, 'validation.number');

            return '';
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function flag(array $body, string $name, ValidationErrors $errors): string
    {
        $value = $body[$name] ?? false;
        if (!is_bool($value)) {
            $errors->add($name, 'api.validation.boolean');

            return '';
        }

        return $value ? '1' : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function text(array $body, string $name, ValidationErrors $errors): string
    {
        $value = $body[$name] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            $errors->add($name, 'api.validation.string');

            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function distanceUnit(array $body, DisplayPreferences $owner, ValidationErrors $errors): DistanceUnit
    {
        $value = $body['distance_unit'] ?? null;
        if ($value === null) {
            return $owner->distanceUnit;
        }
        $unit = is_string($value) ? DistanceUnit::tryFrom($value) : null;
        if ($unit === null) {
            $errors->add('distance_unit', 'validation.choice');

            return $owner->distanceUnit;
        }

        return $unit;
    }

    /**
     * The owner's preferences with the request's units, "." decimals and UTC.
     */
    private static function preferences(DisplayPreferences $owner, DistanceUnit $distance, VolumeUnit $volume): DisplayPreferences
    {
        return new DisplayPreferences(
            self::LOCALE,
            'UTC',
            $distance,
            $volume,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
            $owner->accent,
            $owner->depthUnit,
        );
    }
}
