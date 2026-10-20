<?php

declare(strict_types=1);

namespace Logbook\Support\Api;

use DateTimeImmutable;
use JsonException;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Service\Odometer\OdometerReadingForm;
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
    /** The most rows a parts list (`parts_fitted`, `parts_removed`) takes in one request. */
    private const int MAX_ROWS = 20;

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
        'station_id' => 'station_id',
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

    /** API field → form field, for the issue form (Phase 40.2). */
    public const array ISSUE_FIELDS = [
        'noticed_on' => 'noticed_on',
        'odometer' => 'odometer',
        'title' => 'title',
        'description' => 'description',
        'category' => 'category',
        'status' => 'status',
        'affects_safety' => 'affects_safety',
        'look_again_on' => 'look_again_on',
        'look_again_odometer' => 'look_again_odometer',
    ];

    /** API field → form field, for *Add update* (Phase 40.2). */
    public const array ISSUE_UPDATE_FIELDS = [
        'noted_on' => 'noted_on',
        'odometer' => 'odometer',
        'note' => 'note',
        'status' => 'status',
        'look_again_on' => 'look_again_on',
        'look_again_odometer' => 'look_again_odometer',
    ];

    /** API field → form field, for the part form (Phase 50.2). */
    public const array PART_FIELDS = [
        'name' => 'name',
        'brand' => 'brand',
        'part_number' => 'part_number',
        'serial_number' => 'serial_number',
        'supplier' => 'supplier',
        'bought_on' => 'bought_on',
        'price' => 'price',
        'condition' => 'condition',
        'warranty_months' => 'warranty_months',
        'warranty_distance' => 'warranty_distance',
        'warranty_from' => 'warranty_from',
        'warranty_provider' => 'warranty_provider',
        'warranty_terms' => 'warranty_terms',
        'warranty_reminder' => 'warranty_reminder',
        'notes' => 'notes',
    ];

    /** API field → form field, for the modification form (Phase 50.2). */
    public const array MODIFICATION_FIELDS = [
        'title' => 'title',
        'category' => 'category',
        'done_on' => 'done_on',
        'odometer' => 'odometer',
        'cost' => 'cost',
        'fitted_by' => 'fitted_by',
        'notes' => 'notes',
        'declared_on' => 'declared_on',
    ];

    /** The part fields a *Record what came off* row takes over the API. */
    private const array CAME_OFF_FIELDS = ['name', 'brand', 'part_number', 'outcome'];

    /** API field → form field, for the incident form (Phase 27.1). */
    public const array INCIDENT_FIELDS = [
        'occurred_on' => 'occurred_on',
        'occurred_at_time' => 'occurred_at_time',
        'location' => 'location',
        'type' => 'type',
        'fault' => 'fault',
        'description' => 'description',
        'damage_areas' => 'damage_areas',
        'severity' => 'severity',
        'odometer' => 'odometer',
        'driver_user_id' => 'driver_user_id',
        'driver_name' => 'driver_name',
        'other_party_name' => 'other_party_name',
        'other_party_registration' => 'other_party_registration',
        'other_party_insurer' => 'other_party_insurer',
        'police_reference' => 'police_reference',
        'status' => 'status',
        'closed_on' => 'closed_on',
        'write_off_category' => 'write_off_category',
        'notes' => 'notes',
        'claim_status' => 'claim_status',
        'insurer' => 'insurer',
        'insurance_document_id' => 'insurance_document_id',
        'claim_number' => 'claim_number',
        'excess' => 'excess',
        'payout' => 'payout',
        'repair_estimate' => 'repair_estimate',
        'ncd_affected' => 'ncd_affected',
        'claim_updated_on' => 'claim_updated_on',
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

    /** API field → form field, for the saved journey form (Phase 39.2); in km, one way. */
    public const array JOURNEY_FIELDS = [
        'from' => 'from_place',
        'to' => 'to_place',
        'distance_km' => 'distance',
        'is_return' => 'is_return_default',
        'is_business' => 'is_business_default',
        'purpose' => 'purpose_default',
    ];

    /** API field → form field, for the valuation form (Phase 39.2). */
    public const array VALUATION_FIELDS = [
        'valued_on' => 'valued_on',
        'amount' => 'amount',
        'source' => 'source',
        'notes' => 'notes',
    ];

    /** API field → form field, for the schedule form (Phase 39.2); distances reach it in km. */
    public const array SCHEDULE_FIELDS = [
        'category' => 'category',
        'title' => 'title',
        'interval_km' => 'interval_distance',
        'interval_distance' => 'interval_distance',
        'interval_months' => 'interval_months',
        'baseline_done_on' => 'last_done_on',
        'baseline_odometer' => 'last_done_odometer',
    ];

    /** API field → form field, for the vehicle form (Phase 39.2): the same names. */
    public const array VEHICLE_FIELDS = [
        'type' => 'type',
        'nickname' => 'nickname',
        'make' => 'make',
        'model' => 'model',
        'variant' => 'variant',
        'year' => 'year',
        'first_registered_on' => 'first_registered_on',
        'first_inspection_due_on' => 'first_inspection_due_on',
        'registration' => 'registration',
        'vin' => 'vin',
        'fuel_type' => 'fuel_type',
        'default_grade' => 'default_grade',
        'capacity' => 'capacity',
        'currency' => 'currency',
        'purchase_date' => 'purchase_date',
        'purchase_price' => 'purchase_price',
        'purchase_seller' => 'purchase_seller',
        'purchase_odometer' => 'purchase_odometer',
        'sale_date' => 'sale_date',
        'sale_price' => 'sale_price',
    ];

    /** Only on the add form: the starting reading and the day it was read. */
    public const array NEW_VEHICLE_FIELDS = [
        'current_odometer' => 'current_odometer',
        'current_odometer_on' => 'current_odometer_on',
    ];

    /** The *Archive* page's fields (Phase 39.2, spec.md §7.1). */
    public const array ARCHIVE_FIELDS = [
        'disposal' => 'disposal',
        'incident_id' => 'incident_id',
        'sale_date' => 'sale_date',
        'sale_price' => 'sale_price',
        'settle_from_sale' => 'settle_from_sale',
        'settlement' => 'settlement',
    ];

    /** The agreement form's fields (Phase 39.2, spec.md §7.32): the same names. */
    public const array AGREEMENT_FIELDS = [
        'type' => 'type', 'lender' => 'lender', 'agreement_number' => 'agreement_number',
        'started_on' => 'started_on', 'first_payment_on' => 'first_payment_on',
        'number_of_payments' => 'number_of_payments', 'regular_payment' => 'regular_payment',
        'first_payment' => 'first_payment', 'final_payment' => 'final_payment', 'final_payment_on' => 'final_payment_on',
        'cash_price' => 'cash_price', 'customer_deposit' => 'customer_deposit', 'dealer_contribution' => 'dealer_contribution',
        'initial_rental' => 'initial_rental', 'amount_of_credit' => 'amount_of_credit',
        'total_amount_payable' => 'total_amount_payable', 'apr' => 'apr', 'documentation_fee' => 'documentation_fee',
        'option_to_purchase_fee' => 'option_to_purchase_fee', 'annual_mileage_allowance' => 'annual_mileage_allowance',
        'mileage_unit' => 'mileage_unit', 'excess_mileage_charge' => 'excess_mileage_charge',
        'start_odometer' => 'start_odometer', 'count_in_costs' => 'count_in_costs', 'notes' => 'notes',
        'set_purchase_price' => 'set_purchase_price', 'clear_purchase_price' => 'clear_purchase_price',
    ];
    private const array AGREEMENT_TEXT = [
        'type', 'lender', 'agreement_number', 'started_on', 'first_payment_on', 'final_payment_on', 'mileage_unit', 'notes',
    ];
    private const array AGREEMENT_FLAGS = ['count_in_costs', 'set_purchase_price', 'clear_purchase_price'];

    /** The agreement page's payment, quote and *End* forms (Phase 39.2): API field → form field. */
    public const array PAYMENT_FIELDS = [
        'kind' => 'kind', 'due_on' => 'due_on', 'paid_on' => 'paid_on', 'amount' => 'amount', 'notes' => 'notes',
    ];
    public const array QUOTE_FIELDS = [
        'quoted_on' => 'quoted_on', 'amount' => 'quote_amount', 'valid_until' => 'valid_until', 'notes' => 'quote_notes',
    ];
    public const array END_FIELDS = [
        'outcome' => 'outcome', 'ended_on' => 'ended_on', 'settlement' => 'settlement',
        'excess_charge' => 'excess_charge', 'damage_charge' => 'damage_charge',
    ];

    /** A price alert's fields (Phase 39.2, spec.md §7.34). */
    public const array PRICE_ALERT_FIELDS = ['station_id', 'grade', 'below', 'volume_unit'];

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
            '/"(?>[^"\\\\]++|\\\\.)*+"|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
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
            'station_id' => self::stationId($body, $errors),
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
        } elseif ($unit === 'kg') {
            if ($fuel !== null && $fuel !== Fuel::Cng) {
                $errors->add('volume_unit', 'api.validation.kg_for_cng');
            }
        } elseif ($unit !== '') {
            $volume = VolumeUnit::tryFrom($unit) ?? $volume;
            if (VolumeUnit::tryFrom($unit) === null) {
                $errors->add('volume_unit', 'validation.choice');
            } elseif ($fuel !== null && $fuel->isElectric()) {
                $errors->add('volume_unit', 'api.validation.electric_in_kwh');
            } elseif ($fuel === Fuel::Cng) {
                $errors->add('volume_unit', 'api.validation.cng_in_kg');
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
     * An incident body as the incident form's input (spec.md §7.29 *API*):
     * the date defaults to today, `damage_areas` is a list of codes, ids
     * and amounts are strings, the odometer is in `distance_unit`.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string|list<string>>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function incident(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::INCIDENT_FIELDS), 'distance_unit'], $errors);

        $input = [];
        foreach (self::INCIDENT_FIELDS as $api => $form) {
            $input[$form] = match ($api) {
                'occurred_on' => self::text($body, $api, $errors, $today->format('Y-m-d')),
                'odometer', 'excess', 'payout', 'repair_estimate', 'driver_user_id', 'insurance_document_id'
                    => self::decimal($body, $api, $errors),
                'damage_areas' => self::codes($body, $api, $errors),
                default => self::text($body, $api, $errors),
            };
        }
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * An issue body as the issue form's input (spec.md §7.20 *Issues*).
     * `noticed_on` defaults to today; `status` takes `open` or `watching`
     * only, as the form (fixing is `/fix`).
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function issue(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::ISSUE_FIELDS), 'distance_unit'], $errors);

        $input = [];
        foreach (self::ISSUE_FIELDS as $api => $form) {
            $input[$form] = match ($api) {
                'noticed_on' => self::text($body, $api, $errors, $today->format('Y-m-d')),
                'odometer', 'look_again_odometer' => self::decimal($body, $api, $errors),
                'affects_safety' => self::flag($body, $api, $errors),
                default => self::text($body, $api, $errors),
            };
        }
        if (!in_array($input['status'], ['', 'open', 'watching'], true)) {
            $errors->add('status', 'api.validation.issue_status');
        }
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * *Add update* over the API: `noted_on` (today by default), `odometer`,
     * `note`, an optional `status` and look-again point.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function issueUpdate(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::ISSUE_UPDATE_FIELDS), 'distance_unit'], $errors);

        $input = [];
        foreach (self::ISSUE_UPDATE_FIELDS as $api => $form) {
            $input[$form] = match ($api) {
                'noted_on' => self::text($body, $api, $errors, $today->format('Y-m-d')),
                'odometer', 'look_again_odometer' => self::decimal($body, $api, $errors),
                default => self::text($body, $api, $errors),
            };
        }
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * `/fix`: `records`, a list of service record ids, or none with
     * `fixed_on` (today by default) and an optional `note`.
     *
     * @param array<string, mixed> $body
     * @return array{records: list<int>, input: array<string, string>}|ValidationErrors
     */
    public static function issueFix(array $body, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, ['records', 'fixed_on', 'note'], $errors);
        $raw = $body['records'] ?? [];
        $records = [];
        if (!is_array($raw) || !array_is_list($raw)) {
            $errors->add('records', 'api.validation.list');
        } else {
            foreach ($raw as $id) {
                // Number tokens arrive as strings (decode()).
                if (!is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
                    $errors->add('records', 'api.validation.list');
                    break;
                }
                $records[] = (int) $id;
            }
        }
        $input = [
            'fixed_on' => self::text($body, 'fixed_on', $errors, $today->format('Y-m-d')),
            'note' => self::text($body, 'note', $errors),
        ];
        if ($records !== [] && (($body['fixed_on'] ?? null) !== null || ($body['note'] ?? null) !== null)) {
            $errors->add('records', 'api.validation.issue_fix_either');
        }

        return $errors->isEmpty() ? ['records' => array_values(array_unique($records)), 'input' => $input] : $errors;
    }

    /**
     * A part body as the part form's input (Phase 50.2): the part's own fields, with
     * `warranty_reminder` a boolean and `warranty_months` a whole number.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function part(array $body, DisplayPreferences $owner): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::PART_FIELDS), 'distance_unit'], $errors);

        $input = self::partRow($body, '', $errors);
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A modification body as the modification form's input, with its parts lists as the form's
     * flat fields (Phase 50.2, spec.md §7.20): `parts_fitted` (new parts, or `{"part": id}`) and
     * `parts_removed` (`{"part": id, "outcome": …, "sold_price": …}`, or `{"came_off": {…}}`).
     * Lists are read only when $withLists, so a `PATCH` sends none.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function modification(
        array $body,
        DisplayPreferences $owner,
        DateTimeImmutable $today,
        bool $withLists,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        $lists = $withLists ? ['parts_fitted', 'parts_removed'] : [];
        self::unknownFields($body, [...array_keys(self::MODIFICATION_FIELDS), 'distance_unit', ...$lists], $errors);

        $input = [];
        foreach (self::MODIFICATION_FIELDS as $api => $form) {
            $input[$form] = match ($api) {
                'done_on' => self::text($body, $api, $errors, $today->format('Y-m-d')),
                'odometer', 'cost' => self::decimal($body, $api, $errors),
                default => self::text($body, $api, $errors),
            };
        }
        if ($withLists) {
            $input = [...$input, ...self::partsLists($body, $errors)];
        }
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * `…/modifications/{id}/revert`: the date (today by default), a mileage, a note, and whether
     * the parts it fitted come off and the original parts it took off go back (both on by default).
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences, remove: bool, refit: bool}|ValidationErrors
     */
    public static function modificationRevert(
        array $body,
        DisplayPreferences $owner,
        DateTimeImmutable $today,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        $known = ['reverted_on', 'odometer', 'note', 'remove_parts', 'refit_originals', 'distance_unit'];
        self::unknownFields($body, $known, $errors);
        $input = [
            'reverted_on' => self::text($body, 'reverted_on', $errors, $today->format('Y-m-d')),
            'odometer' => self::decimal($body, 'odometer', $errors),
            'note' => self::text($body, 'note', $errors),
        ];
        $remove = self::flag($body, 'remove_parts', $errors, true) === '1';
        $refit = self::flag($body, 'refit_originals', $errors, true) === '1';
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit),
                'remove' => $remove, 'refit' => $refit]
            : $errors;
    }

    /**
     * `…/parts/{id}/fit` (`fitted_on`, today by default, and `odometer`) and
     * `…/parts/{id}/remove` (`removed_on`, `odometer`, `outcome`, `sold_price`).
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function partMove(
        array $body,
        DisplayPreferences $owner,
        DateTimeImmutable $today,
        bool $remove,
    ): array|ValidationErrors {
        $errors = new ValidationErrors();
        $known = $remove ? ['removed_on', 'odometer', 'outcome', 'sold_price'] : ['fitted_on', 'odometer'];
        self::unknownFields($body, [...$known, 'distance_unit'], $errors);
        $day = $today->format('Y-m-d');
        $input = $remove
            ? [
                'removed_on' => self::text($body, 'removed_on', $errors, $day),
                'odometer' => self::decimal($body, 'odometer', $errors),
                'outcome' => self::text($body, 'outcome', $errors),
                'sold_price' => self::decimal($body, 'sold_price', $errors),
            ]
            : [
                'fit_on' => self::text($body, 'fitted_on', $errors, $day),
                'fit_odometer' => self::decimal($body, 'odometer', $errors),
            ];
        $distance = self::distanceUnit($body, $owner, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $distance, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * One part's fields as form input under $prefix; unknown keys are the caller's to refuse.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function partRow(array $row, string $prefix, ValidationErrors $errors): array
    {
        $input = [];
        foreach (self::PART_FIELDS as $api => $form) {
            $input[$prefix . $form] = match ($api) {
                'price', 'warranty_distance' => self::decimal($row, $api, $errors),
                'warranty_months' => self::whole($row, $api, $errors),
                'warranty_reminder' => self::flag($row, $api, $errors),
                default => self::text($row, $api, $errors),
            };
        }

        return $input;
    }

    /**
     * The parts lists of a modification body as `RecordPartsForm`'s flat fields.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private static function partsLists(array $body, ValidationErrors $errors): array
    {
        $input = ['parts_lists' => '1'];
        $new = 0;
        $off = 0;
        foreach (self::rows($body, 'parts_fitted', $errors) as $i => $row) {
            $field = 'parts_fitted.' . $i;
            if (array_key_exists('part', $row)) {
                $id = self::partId($row, $field, $errors);
                if ($id !== null) {
                    $input['fit_' . $id] = '1';
                }
                self::onlyKeys($row, ['part'], $field, $errors);

                continue;
            }
            $sub = new ValidationErrors();
            self::unknownFields($row, array_keys(self::PART_FIELDS), $sub);
            if (!is_string($row['name'] ?? null) || trim($row['name']) === '') {
                // A row with no name is not a part: refuse it rather than drop it silently.
                $sub->add('name', 'validation.required');
            }
            // The form's row number is the body's, so an error names the row the client sent.
            $input = [...$input, ...self::partRow($row, 'new_' . $i . '_', $sub)];
            self::merge($errors, $sub, $field);
            $new = $i + 1;
        }
        foreach (self::rows($body, 'parts_removed', $errors) as $i => $row) {
            $field = 'parts_removed.' . $i;
            if (array_key_exists('came_off', $row)) {
                $inner = self::object($row['came_off']);
                if ($inner === null) {
                    $errors->add($field . '.came_off', 'api.validation.object');

                    continue;
                }
                $sub = new ValidationErrors();
                self::unknownFields($inner, self::CAME_OFF_FIELDS, $sub);
                foreach (self::CAME_OFF_FIELDS as $name) {
                    $input['off_' . $i . '_' . $name] = self::text($inner, $name, $sub);
                }
                if (!is_string($inner['name'] ?? null) || trim($inner['name']) === '') {
                    // An original part with no name is refused, not dropped.
                    $sub->add('name', 'validation.required');
                }
                self::merge($errors, $sub, $field . '.came_off');
                self::onlyKeys($row, ['came_off'], $field, $errors);
                $off = $i + 1;

                continue;
            }
            $id = self::partId($row, $field, $errors);
            if ($id === null) {
                continue;
            }
            $sub = new ValidationErrors();
            self::unknownFields($row, ['part', 'outcome', 'sold_price'], $sub);
            $input['remove_' . $id] = '1';
            $input['remove_' . $id . '_outcome'] = self::text($row, 'outcome', $sub);
            $input['remove_' . $id . '_sold_price'] = self::decimal($row, 'sold_price', $sub);
            self::merge($errors, $sub, $field);
        }
        $input['new_rows'] = (string) max(1, $new);
        $input['off_rows'] = (string) max(1, $off);

        return $input;
    }

    /**
     * A list of objects: missing is none.
     *
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function rows(array $body, string $name, ValidationErrors $errors): array
    {
        $value = $body[$name] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ROWS) {
            $errors->add($name, 'api.validation.rows', ['max' => self::MAX_ROWS]);

            return [];
        }
        $rows = [];
        foreach ($value as $i => $row) {
            $object = self::object($row);
            if ($object === null) {
                $errors->add($name . '.' . $i, 'api.validation.object');

                continue;
            }
            $rows[] = $object;
        }

        return $rows;
    }

    /**
     * A JSON object (string keys), or null for anything else.
     *
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            return null;
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function partId(array $row, string $field, ValidationErrors $errors): ?int
    {
        $id = $row['part'] ?? null;
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            $errors->add($field . '.part', 'api.validation.part_id');

            return null;
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $known
     */
    private static function onlyKeys(array $row, array $known, string $field, ValidationErrors $errors): void
    {
        $sub = new ValidationErrors();
        self::unknownFields($row, $known, $sub);
        self::merge($errors, $sub, $field);
    }

    /**
     * $inner's errors under a list row's name, e.g. `parts_fitted.0.price`.
     */
    private static function merge(ValidationErrors $errors, ValidationErrors $inner, string $field): void
    {
        foreach ($inner->all() as $name => $error) {
            $errors->add($field . '.' . $name, $error['key'], $error['params']);
        }
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
     * A saved journey body as the journey form's input (Phase 39.2), in km
     * and one way, as `GET /journeys` returns it. A new journey is a
     * business one unless the body says otherwise, as the form starts.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function journey(array $body, DisplayPreferences $owner): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, array_keys(self::JOURNEY_FIELDS), $errors);

        $input = [
            'from_place' => self::text($body, 'from', $errors),
            'to_place' => self::text($body, 'to', $errors),
            'distance' => self::decimal($body, 'distance_km', $errors),
            'is_return_default' => self::flag($body, 'is_return', $errors),
            'is_business_default' => self::flag($body, 'is_business', $errors, true),
            'purpose_default' => self::text($body, 'purpose', $errors),
        ];

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, DistanceUnit::Kilometre, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A valuation body as the valuation form's input (Phase 39.2): the date
     * defaults to today in the owner's time zone.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function valuation(array $body, DisplayPreferences $owner, DateTimeImmutable $today): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, array_keys(self::VALUATION_FIELDS), $errors);

        $input = [
            'valued_on' => self::text($body, 'valued_on', $errors, $today->format('Y-m-d')),
            'amount' => self::decimal($body, 'amount', $errors),
            'source' => self::text($body, 'source', $errors),
            'notes' => self::text($body, 'notes', $errors),
        ];

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, $owner->distanceUnit, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A schedule body as the schedule form's input (Phase 39.2), parsed in
     * km: `interval_km` is km; `interval_distance` and `baseline_odometer`
     * are in `distance_unit` (the owner's when left out) and converted here.
     * One interval field or the other, not both.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function schedule(array $body, DisplayPreferences $owner): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::SCHEDULE_FIELDS), 'distance_unit'], $errors);
        $unit = self::distanceUnit($body, $owner, $errors);
        // Sized before converting, as the form sizes the km (K50): a 20-digit value would overflow the conversion.
        $toKm = static function (string $value, string $field) use ($unit, $errors): string {
            if ($value === '') {
                return '';
            }
            $whole = ltrim(explode('.', ltrim($value, '-'))[0], '0');
            if (strlen($whole) > OdometerReadingForm::MAX_WHOLE_DIGITS) {
                $errors->add($field, 'validation.too_large');

                return '';
            }

            return Decimal::trim($unit->toKmDecimal($value, 3));
        };

        $km = self::decimal($body, 'interval_km', $errors);
        $distance = self::decimal($body, 'interval_distance', $errors);
        if (($body['interval_km'] ?? null) !== null && ($body['interval_distance'] ?? null) !== null) {
            $errors->add('interval_distance', 'api.validation.interval_km_or_distance');
        }
        $input = [
            'category' => self::text($body, 'category', $errors),
            'title' => self::text($body, 'title', $errors),
            'interval_distance' => $km !== '' ? $km : $toKm($distance, 'interval_distance'),
            'interval_months' => self::decimal($body, 'interval_months', $errors),
            'last_done_on' => self::text($body, 'baseline_done_on', $errors),
            'last_done_odometer' => $toKm(self::decimal($body, 'baseline_odometer', $errors), 'baseline_odometer'),
        ];

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($owner, DistanceUnit::Kilometre, $owner->volumeUnit)]
            : $errors;
    }

    /**
     * A vehicle body as the vehicle form's input (Phase 39.2): odometers in
     * `distance_unit`, the capacity in `volume_unit` (the given units'
     * when left out; kWh for electricity, as the form). With `$new`, the
     * add form's starting reading too; a *First MOT due* sent as `null`
     * is cleared rather than suggested, as the form's script says.
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function vehicle(array $body, DisplayPreferences $units, bool $new): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        $fields = $new ? self::VEHICLE_FIELDS + self::NEW_VEHICLE_FIELDS : self::VEHICLE_FIELDS;
        self::unknownFields($body, [...array_keys($fields), 'distance_unit', 'volume_unit'], $errors);

        $input = [];
        foreach ($fields as $api => $form) {
            $input[$form] = match ($api) {
                'year', 'capacity', 'purchase_price', 'purchase_odometer', 'sale_price', 'current_odometer'
                    => self::decimal($body, $api, $errors),
                default => self::text($body, $api, $errors),
            };
        }
        if (array_key_exists('first_inspection_due_on', $body)) {
            // Sent (a date, or null to clear it): never the suggestion (the form's script flag).
            $input['first_inspection_js'] = '1';
        }
        $distance = self::distanceUnit($body, $units, $errors);
        $volume = $units->volumeUnit;
        $unit = $body['volume_unit'] ?? null;
        if ($unit !== null) {
            $volume = is_string($unit) ? VolumeUnit::tryFrom($unit) : null;
            if ($volume === null) {
                $errors->add('volume_unit', 'validation.choice');
                $volume = $units->volumeUnit;
            }
        }

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($units, $distance, $volume)]
            : $errors;
    }

    /**
     * The *Archive* page's fields as its form posts them.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>|ValidationErrors
     */
    public static function archive(array $body): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, array_keys(self::ARCHIVE_FIELDS), $errors);
        $input = [
            'disposal' => self::text($body, 'disposal', $errors),
            'incident_id' => self::decimal($body, 'incident_id', $errors),
            'sale_date' => self::text($body, 'sale_date', $errors),
            'sale_price' => self::decimal($body, 'sale_price', $errors),
            'settle_from_sale' => self::flag($body, 'settle_from_sale', $errors),
            'settlement' => self::decimal($body, 'settlement', $errors),
        ];

        return $errors->isEmpty() ? $input : $errors;
    }

    /**
     * An agreement body as the agreement form's input (Phase 39.2): money
     * and counts as decimals, `start_odometer` in `distance_unit`, the
     * flags as booleans (`count_in_costs` is on unless the body says
     * otherwise, as the form starts).
     *
     * @param array<string, mixed> $body
     * @return array{input: array<string, string>, preferences: DisplayPreferences}|ValidationErrors
     */
    public static function agreement(array $body, DisplayPreferences $units): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, [...array_keys(self::AGREEMENT_FIELDS), 'distance_unit'], $errors);
        $input = [];
        foreach (self::AGREEMENT_FIELDS as $api => $form) {
            $input[$form] = match (true) {
                in_array($api, self::AGREEMENT_TEXT, true) => self::text($body, $api, $errors),
                in_array($api, self::AGREEMENT_FLAGS, true) => self::flag($body, $api, $errors, $api === 'count_in_costs'),
                default => self::decimal($body, $api, $errors),
            };
        }
        $distance = self::distanceUnit($body, $units, $errors);

        return $errors->isEmpty()
            ? ['input' => $input, 'preferences' => self::preferences($units, $distance, $units->volumeUnit)]
            : $errors;
    }

    /**
     * One of the agreement page's small forms (payment, quote, *End*) as it posts.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $fields API field → form field
     * @param list<string> $flags fields that are booleans
     * @return array<string, string>|ValidationErrors
     */
    public static function form(array $body, array $fields, array $flags = []): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, array_keys($fields), $errors);
        $input = [];
        foreach ($fields as $api => $form) {
            $value = $body[$api] ?? null;
            $input[$form] = match (true) {
                in_array($api, $flags, true) => self::flag($body, $api, $errors),
                is_string($value) && preg_match(self::NUMBER, $value) === 1 => $value,
                default => self::text($body, $api, $errors),
            };
        }

        return $errors->isEmpty() ? $input : $errors;
    }

    /**
     * A price alert body (Phase 39.2): the station, the grade, and the
     * price per `volume_unit` (the owner's when left out) as a decimal.
     *
     * @param array<string, mixed> $body
     * @return array{station_id: string, grade: string, below: string, volume: VolumeUnit}|ValidationErrors
     */
    public static function priceAlert(array $body, DisplayPreferences $owner): array|ValidationErrors
    {
        $errors = new ValidationErrors();
        self::unknownFields($body, self::PRICE_ALERT_FIELDS, $errors);
        $volume = $owner->volumeUnit;
        $unit = $body['volume_unit'] ?? null;
        if ($unit !== null) {
            $volume = is_string($unit) ? VolumeUnit::tryFrom($unit) : null;
            if ($volume === null) {
                $errors->add('volume_unit', 'validation.choice');
                $volume = $owner->volumeUnit;
            }
        }
        $values = [
            'station_id' => self::stationId($body, $errors),
            'grade' => self::text($body, 'grade', $errors),
            'below' => self::decimal($body, 'below', $errors),
            'volume' => $volume,
        ];

        return $errors->isEmpty() ? $values : $errors;
    }

    /**
     * A `PATCH` body laid over the stored entry (spec.md §7.20, #283): the
     * edit form's values for the entry, with each field the body sends
     * replaced by its converted value, or emptied when it is `null`. What
     * the body leaves out keeps its stored value, never the create
     * mapper's default (now, today, the usual fuel).
     *
     * @param array<string, string|list<string>> $stored the edit form's values, in the mapped preferences
     * @param array<string, mixed> $body the decoded request body
     * @param array<string, string|list<string>> $mapped the create mapper's input for the same body
     * @param array<string, string> $fields API field → form field
     * @return array<string, string|list<string>>
     */
    public static function overlay(array $stored, array $body, array $mapped, array $fields): array
    {
        foreach ($body as $name => $value) {
            $form = $fields[$name] ?? null;
            if ($form === null) {
                continue;
            }
            $stored[$form] = $value === null ? (is_array($stored[$form] ?? null) ? [] : '') : ($mapped[$form] ?? '');
        }

        return $stored;
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
     * A list of codes (strings); missing is an empty list.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private static function codes(array $body, string $name, ValidationErrors $errors): array
    {
        $value = $body[$name] ?? [];
        if (!is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            $errors->add($name, 'api.validation.list');

            return [];
        }

        return $value;
    }

    /**
     * A whole number (sent as a number token, so a string here), or empty.
     *
     * @param array<string, mixed> $body
     */
    private static function whole(array $body, string $name, ValidationErrors $errors): string
    {
        $value = $body[$name] ?? null;
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,6}$/', trim($value)) !== 1) {
            $errors->add($name, 'validation.integer');

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
     * A fill-up's `station_id` (Phase 30.1): a positive whole number, never
     * with `station` as well (spec.md §7.33 *API*).
     *
     * @param array<string, mixed> $body
     */
    private static function stationId(array $body, ValidationErrors $errors): string
    {
        $value = $body['station_id'] ?? null;
        if ($value === null) {
            return '';
        }
        // Number tokens arrive as strings (decode()).
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1) {
            $errors->add('station_id', 'validation.integer');

            return '';
        }
        if (($body['station'] ?? null) !== null) {
            $errors->add('station_id', 'api.validation.station_or_id');

            return '';
        }

        return $value;
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
