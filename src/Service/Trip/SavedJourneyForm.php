<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\SavedJourney;
use Logbook\Domain\Trip\SavedJourneyData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Saved journey form ↔ SavedJourneyData (spec.md §7.22, Settings → Trips).
 * The distance is one way, in the user's distance unit.
 */
final class SavedJourneyForm
{
    /**
     * @return array<string, string>
     */
    public static function values(SavedJourney $journey, DisplayPreferences $preferences): array
    {
        $data = $journey->data;

        return [
            'from_place' => $data->fromPlace,
            'to_place' => $data->toPlace,
            'distance' => Decimal::trim($preferences->distanceUnit->fromKmDecimal($data->distanceKm, TripForm::KM_SCALE)),
            'is_return_default' => $data->isReturnDefault ? '1' : '',
            'purpose_default' => $data->purposeDefault ?? '',
            'is_business_default' => $data->isBusinessDefault ? '1' : '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): SavedJourneyData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);
        $from = $validator->string('from_place', true, TripForm::PLACE_MAX);
        $to = $validator->string('to_place', true, TripForm::PLACE_MAX);
        $distance = $validator->decimal('distance', true, TripForm::KM_SCALE, '0', null, TripForm::WHOLE_DIGITS);
        $purpose = $validator->string('purpose_default', false, TripForm::PURPOSE_MAX);

        if (!$validator->errors()->isEmpty() || $from === null || $to === null || $distance === null) {
            return $validator->errors();
        }

        return new SavedJourneyData(
            fromPlace: $from,
            toPlace: $to,
            distanceKm: $preferences->distanceUnit->toKmDecimal($distance, TripForm::KM_SCALE),
            isReturnDefault: $validator->checkbox('is_return_default'),
            purposeDefault: $purpose,
            isBusinessDefault: $validator->checkbox('is_business_default'),
        );
    }
}
