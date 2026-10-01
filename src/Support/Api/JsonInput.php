<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use DateTimeImmutable;
use JsonException;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;

/**
 * JSON request bodies → the form input the fill-up, reading, trip, service
 * record, document, expense, tread check and manual reminder forms parse
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

    /** API field → form field, for the trip form. */
    public const array TRIP_FIELDS = [
        'travelled_on' => 'travelled_on',
        'from' => 'from_place',
        'to' => 'to_place',
        'is_return' => 'is_return',
        'distance_km' => 'distance',
        'odometer_start_km' => 'odometer_start',
        'odometer_end_km' => 'odometer_end',
        'is_business' => 'is_business',
        'purpose' => 'purpose',
        'passengers' => 'passengers',
        'notes' => 'notes',
    ];
    private const array TRIP_EXTRA = ['journey_id'];

    /** API field → form field, for the service record form (Phase 26.3). */
    public const array MAINTENANCE_FIELDS = [
        'performed_on' => 'performed_on',
        'odometer' => 'odometer',
        'category' => 'category',
        'title' => 'title',
        'cost' => 'cost',
        'vendor' => 'vendor',
        'description' => 'description',
        'schedule_id' => 'schedule',
    ];

    /** API field → form field, for the document form (Phase 26.3). */
    public const array DOCUMENT_FIELDS = [
        'type' => 'type',
        'title' => 'title',
        'provider' => 'provider',
        'reference' => 'reference',
        'start_on' => 'start_on',
        'expiry_on' => 'expiry_on',
        'cost' => 'cost',
        'odometer' => 'odometer',
        'notes' => 'notes',
    ];

    /** API field → form field, for the expense form (Phase 26.3). */
    public const array EXPENSE_FIELDS = [
        'spent_on' => 'spent_on',
        'category' => 'category',
        'amount' => 'amount',
        'note' => 'note',
    ];

    /** API field → form field, for *Check tread* (Phase 26.3); depths become `tread_{tyre}`. */
    public const array TREAD_CHECK_FIELDS = [
        'checked_on' => 'done_on',
        'odometer' => 'odometer',
        'note' => 'note',
        'depths' => 'depths',
    ];
    private const array TREAD_CHECK_EXTRA = ['distance_unit', 'depth_unit'];

    /** API field → form field, for the manual reminder form (Phase 26.3). */
    public const array REMINDER_FIELDS = [
        'title' => 'title',
        'due_on' => 'due_on',
        'due_odometer' => 'due_odometer',
        'lead_time_days' => 'lead_time_days',
        'notes' => 'notes',
    ];

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
     * A trip body as the trip form's input, in kilometres (the whole trip,
     * never doubled), and the preferences to parse it with. A saved
     * journey (`journey_id`, looked up by the caller) fills the places,
     * return, business and purpose, and the distance (one way, so doubled
     * on a return) unless the body gives a distance or odometers; the
     * body's own fields win.
     *
     * @param array<string, mixed> $body
     * @param (callable(int): ?SavedJourney) $journey the key user's saved journey by id, or null
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function trip(
        array $body,
        DisplayPreferences $owner,
        DateTimeImmutable $today,
        callable $journey,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::TRIP_FIELDS), ...self::TRIP_EXTRA], $errors);

        $saved = null;
        if (array_key_exists('journey_id', $body) && $body['journey_id'] !== null) {
            $id = $body['journey_id'];
            $saved = is_string($id) && ctype_digit($id) ? $journey((int) $id) : null;
            if ($saved === null) {
                $errors->add('journey_id', 'validation.choice');
            }
        }
        $from = $saved?->data;

        $input = [
            'travelled_on' => self::text($body, 'travelled_on', $errors, $today->format('Y-m-d')),
            'from_place' => self::text($body, 'from', $errors, $from?->fromPlace),
            'to_place' => self::text($body, 'to', $errors, $from?->toPlace),
            'is_return' => self::flag($body, 'is_return', $errors, $from->isReturnDefault ?? false),
            'distance' => self::decimal($body, 'distance_km', $errors),
            'odometer_start' => self::decimal($body, 'odometer_start_km', $errors),
            'odometer_end' => self::decimal($body, 'odometer_end_km', $errors),
            // Missing counts as business in the form; false must reach it as unticked.
            'is_business' => self::flag($body, 'is_business', $errors, $from->isBusinessDefault ?? true),
            'purpose' => self::text($body, 'purpose', $errors, $from?->purposeDefault),
            'passengers' => self::decimal($body, 'passengers', $errors),
            'notes' => self::text($body, 'notes', $errors),
        ];
        $measured = array_key_exists('distance_km', $body)
            || array_key_exists('odometer_start_km', $body)
            || array_key_exists('odometer_end_km', $body);
        if ($from !== null && !$measured) {
            $input['distance'] = $input['is_return'] === '1'
                ? Decimal::add($from->distanceKm, $from->distanceKm)
                : $from->distanceKm;
        }

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, DistanceUnit::Kilometre, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A service record body as the maintenance form's input.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function maintenance(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::MAINTENANCE_FIELDS), 'distance_unit'], $errors);

        $input = [
            'performed_on' => self::text($body, 'performed_on', $errors, $today->format('Y-m-d')),
            'odometer' => self::decimal($body, 'odometer', $errors),
            'category' => self::text($body, 'category', $errors),
            'title' => self::text($body, 'title', $errors),
            'cost' => self::decimal($body, 'cost', $errors),
            'vendor' => self::text($body, 'vendor', $errors),
            'description' => self::text($body, 'description', $errors),
            'schedule' => self::decimal($body, 'schedule_id', $errors),
        ];
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A document body as the document form's input. Dates are optional, as
     * on the form.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function document(array $body, DisplayPreferences $owner): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::DOCUMENT_FIELDS), 'distance_unit'], $errors);

        $input = [
            'type' => self::text($body, 'type', $errors),
            'title' => self::text($body, 'title', $errors),
            'provider' => self::text($body, 'provider', $errors),
            'reference' => self::text($body, 'reference', $errors),
            'start_on' => self::text($body, 'start_on', $errors),
            'expiry_on' => self::text($body, 'expiry_on', $errors),
            'cost' => self::decimal($body, 'cost', $errors),
            'odometer' => self::decimal($body, 'odometer', $errors),
            'notes' => self::text($body, 'notes', $errors),
        ];
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * An expense body as the expense form's input.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function expense(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, array_keys(self::EXPENSE_FIELDS), $errors);

        $input = [
            'spent_on' => self::text($body, 'spent_on', $errors, $today->format('Y-m-d')),
            'category' => self::text($body, 'category', $errors),
            'amount' => self::decimal($body, 'amount', $errors),
            'note' => self::text($body, 'note', $errors),
        ];

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $owner->distanceUnit, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A tread check body as *Check tread*'s input: each position's depth
     * goes to the tyre fitted there (`tread_{id}`). A position with no
     * fitted tyre is refused, as the form never offers it.
     *
     * @param array<string, mixed> $body
     * @param array<string, int> $fitted position code → fitted tyre id
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function treadCheck(
        array $body,
        DisplayPreferences $owner,
        DateTimeImmutable $today,
        array $fitted,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::TREAD_CHECK_FIELDS), ...self::TREAD_CHECK_EXTRA], $errors);

        $input = [
            'done_on' => self::text($body, 'checked_on', $errors, $today->format('Y-m-d')),
            'odometer' => self::decimal($body, 'odometer', $errors),
            'note' => self::text($body, 'note', $errors),
        ];
        $depths = $body['depths'] ?? [];
        if (!is_array($depths) || ($depths !== [] && array_is_list($depths))) {
            $errors->add('depths', 'api.validation.depths');
            $depths = [];
        }
        foreach ($depths as $position => $depth) {
            $field = 'depths.' . $position;
            $tyre = $fitted[(string) $position] ?? null;
            if ($tyre === null) {
                $errors->add($field, 'api.validation.no_tyre_at_position');
                continue;
            }
            $input['tread_' . $tyre] = self::decimal([$field => $depth], $field, $errors);
        }

        $distance = self::distanceUnit($body, $owner, $errors);
        $depthUnit = $owner->depthUnit;
        $unit = $body['depth_unit'] ?? null;
        if ($unit !== null) {
            $depthUnit = is_string($unit) ? DepthUnit::tryFrom($unit) : null;
            if ($depthUnit === null) {
                $errors->add('depth_unit', 'validation.choice');
                $depthUnit = $owner->depthUnit;
            }
        }

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit, $depthUnit)]
            : $errors;
    }

    /**
     * A manual reminder body as the reminder form's input, for this vehicle.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function reminder(
        array $body,
        DisplayPreferences $owner,
        int $vehicleId,
        int $defaultLeadDays,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::REMINDER_FIELDS), 'distance_unit'], $errors);

        $lead = self::decimal($body, 'lead_time_days', $errors);
        $input = [
            'vehicle_id' => (string) $vehicleId,
            'title' => self::text($body, 'title', $errors),
            'due_on' => self::text($body, 'due_on', $errors),
            'due_odometer' => self::decimal($body, 'due_odometer', $errors),
            'lead_time_days' => array_key_exists('lead_time_days', $body) ? $lead : (string) $defaultLeadDays,
            'notes' => self::text($body, 'notes', $errors),
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
    private static function flag(array $body, string $name, ValidationErrors $errors, bool $default = false): string
    {
        $value = $body[$name] ?? $default;
        if (!is_bool($value)) {
            $errors->add($name, 'api.validation.boolean');

            return '';
        }

        return $value ? '1' : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function text(array $body, string $name, ValidationErrors $errors, ?string $default = null): string
    {
        $value = $body[$name] ?? null;
        if ($value === null) {
            return $default ?? '';
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
    private static function preferences(
        DisplayPreferences $owner,
        DistanceUnit $distance,
        VolumeUnit $volume,
        ?DepthUnit $depth = null,
    ): DisplayPreferences {
        return new DisplayPreferences(
            self::LOCALE,
            'UTC',
            $distance,
            $volume,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
            $owner->accent,
            $depth ?? $owner->depthUnit,
        );
    }
}
