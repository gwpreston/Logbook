<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\TaxYear;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Settings → Trips (spec.md §6 *Trip settings*): the tax year start as a
 * day and month, and the claim report's declaration.
 */
final class TripSettingsForm
{
    /**
     * @return array<string, string>
     */
    public static function values(TripSettings $settings): array
    {
        [$month, $day] = explode('-', $settings->taxYearStart);

        return [
            'tax_year_day' => (string) (int) $day,
            'tax_year_month' => (string) (int) $month,
            'declaration' => $settings->declaration ?? '',
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, string $locale, TripSettings $current): TripSettings|ValidationErrors
    {
        $validator = new Validator($input, $locale);
        $day = $validator->integer('tax_year_day', true, 1, 31);
        $month = $validator->integer('tax_year_month', true, 1, 12);
        $declaration = $validator->string('declaration', false, TripSettings::DECLARATION_MAX);

        $start = $day === null || $month === null ? null : sprintf('%02d-%02d', $month, $day);
        if ($start !== null && !TaxYear::isValidStart($start)) {
            $validator->addError('tax_year_day', 'trip.settings.error.start');
        }
        if (!$validator->errors()->isEmpty() || $start === null) {
            return $validator->errors();
        }

        return $current->with($start, $declaration);
    }
}
