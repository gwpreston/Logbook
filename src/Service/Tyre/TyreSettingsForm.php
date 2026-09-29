<?php

declare(strict_types=1);

namespace Logbook\Service\Tyre;

use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * Settings → Tyres form (spec.md §7.17): replace-at, winter replace-at and
 * legal-minimum depths typed in the owner's depth unit, and the age limit.
 *
 * A depth sent back as it was shown keeps its stored millimetres, so the
 * 3.0 mm default shown as 4/32″ is not saved as 3.175 mm.
 */
final class TyreSettingsForm
{
    /** Field → TyreThresholds property, for the depths. */
    private const array DEPTHS = [
        'car_replace' => 'carReplaceMm',
        'car_winter_replace' => 'carWinterReplaceMm',
        'car_legal' => 'carLegalMm',
        'bike_replace' => 'bikeReplaceMm',
        'bike_legal' => 'bikeLegalMm',
    ];

    /**
     * @return array<string, string>
     */
    public static function values(TyreThresholds $thresholds, DisplayPreferences $display): array
    {
        $values = ['age_years' => (string) $thresholds->ageYears];
        foreach (self::DEPTHS as $field => $property) {
            $values[$field] = $display->depthUnit->toInput(self::stored($thresholds, $property));
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        array $input,
        DisplayPreferences $display,
        TyreThresholds $current,
    ): TyreThresholds|ValidationErrors {
        $validator = new Validator($input, $display->locale);
        $unit = $display->depthUnit;
        $mm = [];
        foreach (self::DEPTHS as $field => $property) {
            $mm[$property] = self::depth($validator, $field, $unit, self::stored($current, $property));
        }
        $age = $validator->integer('age_years', true, 0, TyreThresholds::MAX_AGE_YEARS);

        if (!$validator->errors()->isEmpty() || $age === null || in_array(null, $mm, true)) {
            return $validator->errors();
        }

        return new TyreThresholds(
            (string) $mm['carReplaceMm'],
            (string) $mm['carWinterReplaceMm'],
            (string) $mm['carLegalMm'],
            (string) $mm['bikeReplaceMm'],
            (string) $mm['bikeLegalMm'],
            $age,
        );
    }

    private static function depth(Validator $validator, string $field, DepthUnit $unit, string $stored): ?string
    {
        $value = $validator->decimal($field, true, $unit->inputScale(), '0', $unit->max(), 2);
        if ($value === null) {
            return null;
        }
        $problem = $unit->problem($value);
        if ($problem !== null) {
            $validator->addError($field, $problem, ['max' => $unit->max()]);

            return null;
        }

        // Unchanged from what was shown: keep the stored value exactly.
        return $unit->toInput($stored) === $unit->toInput($unit->toMm($value)) ? $stored : $unit->toMm($value);
    }

    private static function stored(TyreThresholds $thresholds, string $property): string
    {
        return match ($property) {
            'carReplaceMm' => $thresholds->carReplaceMm,
            'carWinterReplaceMm' => $thresholds->carWinterReplaceMm,
            'carLegalMm' => $thresholds->carLegalMm,
            'bikeReplaceMm' => $thresholds->bikeReplaceMm,
            default => $thresholds->bikeLegalMm,
        };
    }
}
