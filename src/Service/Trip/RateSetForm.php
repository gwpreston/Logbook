<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use DateTimeImmutable;
use Logbook\Domain\Trip\MileageRateSet;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Money\Currency;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Mileage rate set form ↔ MileageRateSetData (spec.md §7.23). Rates are per
 * unit of the set's own distance unit, to 4 places; a threshold needs the
 * rate after it.
 */
final class RateSetForm
{
    public const int RATE_SCALE = 4;
    /** decimal(10,4). */
    public const int RATE_WHOLE_DIGITS = 6;
    public const int SOURCE_MAX = 200;

    private const array RATES = ['car_rate', 'car_rate_after', 'bike_rate', 'passenger_rate', 'employer_car_rate', 'employer_bike_rate'];

    /**
     * @return array<string, string>
     */
    public static function values(MileageRateSetData $data): array
    {
        $values = [
            'effective_from' => $data->effectiveFrom->format('Y-m-d'),
            'distance_unit' => $data->distanceUnit->value,
            'currency' => $data->currency,
            'car_threshold' => $data->carThreshold === null ? '' : Decimal::trim($data->carThreshold),
            'source' => $data->source ?? '',
        ];
        $rates = [
            'car_rate' => $data->carRate,
            'car_rate_after' => $data->carRateAfter,
            'bike_rate' => $data->bikeRate,
            'passenger_rate' => $data->passengerRate,
            'employer_car_rate' => $data->employerCarRate,
            'employer_bike_rate' => $data->employerBikeRate,
        ];
        foreach ($rates as $field => $rate) {
            $values[$field] = $rate === null ? '' : Decimal::trim($rate);
        }

        return $values;
    }

    /**
     * The add form: the set in effect today, or the user's units and currency.
     *
     * @return array<string, string>
     */
    public static function defaults(?MileageRateSet $current, DisplayPreferences $preferences, DateTimeImmutable $today): array
    {
        if ($current !== null) {
            return ['effective_from' => $today->format('Y-m-d')] + self::values($current->data);
        }

        return [
            'effective_from' => $today->format('Y-m-d'),
            'distance_unit' => $preferences->distanceUnit->value,
            'currency' => $preferences->currency,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, DisplayPreferences $preferences): MileageRateSetData|ValidationErrors
    {
        $validator = new Validator($input, $preferences->locale);
        $from = $validator->date('effective_from', true);
        $unit = $validator->enum('distance_unit', DistanceUnit::class, true);
        $currency = $validator->choice('currency', Currency::SUPPORTED, true);
        $rates = [];
        foreach (self::RATES as $field) {
            $rates[$field] = $validator->decimal($field, $field === 'car_rate', self::RATE_SCALE, '0', null, self::RATE_WHOLE_DIGITS);
        }
        $threshold = $validator->decimal('car_threshold', false, 3, '0', null, TripForm::WHOLE_DIGITS);
        if ($threshold !== null && $rates['car_rate_after'] === null && $validator->raw('car_rate_after') === '') {
            $validator->addError('car_rate_after', 'trip.rates.error.rate_after');
        }
        $source = $validator->string('source', false, self::SOURCE_MAX);

        if (
            !$validator->errors()->isEmpty()
            || $from === null
            || $unit === null
            || $currency === null
            || $rates['car_rate'] === null
        ) {
            return $validator->errors();
        }

        return new MileageRateSetData(
            effectiveFrom: $from,
            distanceUnit: $unit,
            currency: $currency,
            carRate: $rates['car_rate'],
            carThreshold: $threshold,
            carRateAfter: $threshold === null ? null : $rates['car_rate_after'],
            bikeRate: $rates['bike_rate'],
            passengerRate: $rates['passenger_rate'],
            employerCarRate: $rates['employer_car_rate'],
            employerBikeRate: $rates['employer_bike_rate'],
            source: $source,
        );
    }
}
