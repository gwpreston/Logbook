<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\User;
use Logbook\Service\User\PreferenceFields;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The short form a user created through single sign-on sees once (spec.md
 * §7.9 *Finding the user* 3): language, time zone, unit preset and
 * currency, as an invitation asks. Theme and accent stay as they are.
 */
final class WelcomeForm
{
    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(
        array $input,
        string $locale,
        AvailableLocales $locales,
        User $user,
    ): DisplayPreferences|ValidationErrors {
        $validator = new Validator($input, $locale);
        $preferences = PreferenceFields::read($validator, $locales);
        $preset = $validator->enum('units', UnitPreset::class, true);

        if (
            !$validator->errors()->isEmpty()
            || $preset === null
            || $preferences['locale'] === null
            || $preferences['timezone'] === null
            || $preferences['currency'] === null
        ) {
            return $validator->errors();
        }

        return new DisplayPreferences(
            locale: $preferences['locale'],
            timezone: $preferences['timezone'],
            distanceUnit: $preset->distance(),
            volumeUnit: $preset->volume(),
            consumptionUnit: $preset->consumption(),
            currency: $preferences['currency'],
            theme: $user->preferences->theme,
            accent: $user->preferences->accent,
            depthUnit: $preset->depth(),
        );
    }
}
