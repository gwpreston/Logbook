<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\Trip;
use Logbook\Domain\Trip\TripData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;
use NumberFormatter;

/**
 * Trip form ↔ TripData (spec.md §7.22), the one parser for the form, CSV
 * import and the API. Distances are typed in the user's distance unit and
 * stored in kilometres, converted once.
 *
 * - The typed distance is one way on a return, and doubled.
 * - With both odometers, the distance is end − start: already the whole
 *   trip, never doubled. A typed distance (doubled on a return) that
 *   differs from it by more than 0.5 is refused.
 * - A business trip needs a purpose. `is_business` missing counts as
 *   business (import and API); the form always sends it.
 * - Import and the API read and write the whole trip's distance, as it is
 *   stored and exported ($wholeDistance): nothing is doubled.
 */
final class TripForm
{
    public const int PLACE_MAX = 100;
    public const int PURPOSE_MAX = 200;
    public const int NOTES_MAX = 500;
    public const int PASSENGERS_MAX = 8;
    public const int KM_SCALE = 3;
    /** decimal(12,3). */
    public const int WHOLE_DIGITS = 9;
    /** How far a typed distance may differ from the odometers, in the user's unit. */
    public const string TOLERANCE = '0.5';

    /**
     * @return array<string, string>
     */
    public static function defaults(DateTimeImmutable $today): array
    {
        return ['travelled_on' => $today->format('Y-m-d'), 'is_business' => '1', 'passengers' => '0'];
    }

    /**
     * An existing trip as the edit form shows it.
     *
     * @return array<string, string>
     */
    public static function values(Trip $trip, DisplayPreferences $preferences): array
    {
        $data = $trip->data;
        $unit = $preferences->distanceUnit;
        $hasOdometers = $data->odometerStartKm !== null && $data->odometerEndKm !== null;

        return [
            'travelled_on' => $data->travelledOn->format('Y-m-d'),
            'odometer_start' => $data->odometerStartKm === null ? '' : self::inUnit($data->odometerStartKm, $unit),
            'odometer_end' => $data->odometerEndKm === null ? '' : self::inUnit($data->odometerEndKm, $unit),
            // With odometers, the distance comes from them; the field stays empty.
            'distance' => $hasOdometers ? '' : self::oneWay($data->distanceKm, $data->isReturn, $unit),
        ] + self::journeyFields($data->fromPlace, $data->toPlace, $data->isReturn, $data->isBusiness, $data->purpose)
            + ['passengers' => (string) $data->passengers, 'notes' => $data->notes ?? ''];
    }

    /**
     * *Log again*: every field but the date and odometers (spec.md §7.22).
     *
     * @return array<string, string>
     */
    public static function again(Trip $trip, DisplayPreferences $preferences, DateTimeImmutable $today): array
    {
        $data = $trip->data;

        return [
            'travelled_on' => $today->format('Y-m-d'),
            'distance' => self::oneWay($data->distanceKm, $data->isReturn, $preferences->distanceUnit),
        ] + self::journeyFields($data->fromPlace, $data->toPlace, $data->isReturn, $data->isBusiness, $data->purpose)
            + ['passengers' => (string) $data->passengers, 'notes' => $data->notes ?? ''];
    }

    /**
     * A saved journey's fields (`?journey=<id>`, and what the select fills in).
     *
     * @return array<string, string>
     */
    public static function fromJourney(SavedJourney $journey, DisplayPreferences $preferences, DateTimeImmutable $today): array
    {
        $data = $journey->data;

        return [
            'travelled_on' => $today->format('Y-m-d'),
            'journey_id' => (string) $journey->id,
            'distance' => self::inUnit($data->distanceKm, $preferences->distanceUnit),
            'passengers' => '0',
        ] + self::journeyFields($data->fromPlace, $data->toPlace, $data->isReturnDefault, $data->isBusinessDefault, $data->purposeDefault);
    }

    /**
     * @param array<array-key, mixed> $input
     * @param DateTimeImmutable $today calendar date in the user's time zone
     * @param bool $wholeDistance the typed distance is the whole trip (import, API), never doubled
     */
    public static function parse(
        array $input,
        DisplayPreferences $preferences,
        DateTimeImmutable $today,
        bool $wholeDistance = false,
    ): TripData|ValidationErrors {
        $validator = new Validator($input, $preferences->locale);
        $unit = $preferences->distanceUnit;

        $travelledOn = $validator->date('travelled_on', true);
        $from = $validator->string('from_place', true, self::PLACE_MAX);
        $to = $validator->string('to_place', true, self::PLACE_MAX);
        $isReturn = $validator->checkbox('is_return');
        $isBusiness = !array_key_exists('is_business', $input) || $validator->checkbox('is_business');
        $purpose = $validator->string('purpose', false, self::PURPOSE_MAX);
        $passengers = $validator->integer('passengers', false, 0, self::PASSENGERS_MAX) ?? 0;
        $notes = $validator->string('notes', false, self::NOTES_MAX);

        $typed = $validator->decimal('distance', false, self::KM_SCALE, '0', null, self::WHOLE_DIGITS);
        $start = $validator->decimal('odometer_start', false, self::KM_SCALE, '0', null, self::WHOLE_DIGITS);
        $end = $validator->decimal('odometer_end', false, self::KM_SCALE, '0', null, self::WHOLE_DIGITS);

        if ($travelledOn !== null && $travelledOn > $today) {
            $validator->addError('travelled_on', 'trip.error.future');
        }
        if ($isBusiness && $purpose === null && $validator->raw('purpose') === '') {
            $validator->addError('purpose', 'trip.error.purpose');
        }

        $distance = null;
        $hasStart = $validator->raw('odometer_start') !== '';
        $hasEnd = $validator->raw('odometer_end') !== '';
        $whole = $typed === null ? null : ($isReturn && !$wholeDistance ? Decimal::add($typed, $typed) : $typed);
        if ($hasStart !== $hasEnd) {
            $validator->addError($hasStart ? 'odometer_end' : 'odometer_start', 'trip.error.odometer_pair');
        } elseif ($start !== null && $end !== null) {
            if (Decimal::compare($end, $start) <= 0) {
                $validator->addError('odometer_end', 'trip.error.odometer_order');
            } else {
                $distance = Decimal::subtract($end, $start);
                if ($whole !== null && Decimal::compare(self::abs(Decimal::subtract($whole, $distance)), self::TOLERANCE) > 0) {
                    $validator->addError('distance', 'trip.error.odometer_mismatch', [
                        'odometer' => self::number($distance, $preferences->locale),
                        'distance' => self::number($whole, $preferences->locale),
                        'unit' => $unit->value,
                    ]);
                }
            }
        } elseif (!$hasStart && $validator->raw('distance') === '') {
            $validator->addError('distance', 'trip.error.distance');
        } else {
            $distance = $whole;
        }

        if (
            !$validator->errors()->isEmpty()
            || $travelledOn === null
            || $from === null
            || $to === null
            || $distance === null
        ) {
            return $validator->errors();
        }

        return new TripData(
            travelledOn: $travelledOn,
            fromPlace: $from,
            toPlace: $to,
            isReturn: $isReturn,
            distanceKm: $unit->toKmDecimal($distance, self::KM_SCALE),
            odometerStartKm: $start === null ? null : $unit->toKmDecimal($start, self::KM_SCALE),
            odometerEndKm: $end === null ? null : $unit->toKmDecimal($end, self::KM_SCALE),
            isBusiness: $isBusiness,
            purpose: $purpose,
            passengers: $passengers,
            notes: $notes,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function journeyFields(string $from, string $to, bool $isReturn, bool $isBusiness, ?string $purpose): array
    {
        return [
            'from_place' => $from,
            'to_place' => $to,
            'is_return' => $isReturn ? '1' : '',
            'is_business' => $isBusiness ? '1' : '',
            'purpose' => $purpose ?? '',
        ];
    }

    private static function oneWay(string $km, bool $isReturn, DistanceUnit $unit): string
    {
        $value = $unit->fromKmDecimal($km, self::KM_SCALE);

        return Decimal::trim($isReturn ? Decimal::divide($value, '2', self::KM_SCALE) : $value);
    }

    private static function inUnit(string $km, DistanceUnit $unit): string
    {
        return Decimal::trim($unit->fromKmDecimal($km, self::KM_SCALE));
    }

    private static function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    private static function number(string $value, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $formatted = $formatter->format((float) $value);

        return is_string($formatted) ? $formatted : Decimal::trim($value);
    }
}
