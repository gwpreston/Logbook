<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\User;
use Logbook\Support\Display\Accent;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Display\Theme;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The settings page's preferences form: display name, appearance, units,
 * currency, language and time zone.
 */
final class ProfileForm
{
    /**
     * Current values, for pre-filling the form.
     *
     * @return array<string, string>
     */
    public static function values(User $user): array
    {
        $preferences = $user->preferences;

        return [
            'display_name' => $user->displayName,
            'theme' => $preferences->theme->value,
            'accent' => $preferences->accent->value,
            'distance_unit' => $preferences->distanceUnit->value,
            'volume_unit' => $preferences->volumeUnit->value,
            'consumption_unit' => $preferences->consumptionUnit->value,
            'currency' => $preferences->currency,
            'locale' => $preferences->locale,
            'timezone' => $preferences->timezone,
        ];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, string $locale, AvailableLocales $locales): ProfileData|ValidationErrors
    {
        $validator = new Validator($input, $locale);

        $displayName = $validator->string('display_name', true, 100);
        $theme = $validator->enum('theme', Theme::class, true);
        // Optional: a form without the chips (an older page) keeps the default.
        $accent = $validator->enum('accent', Accent::class, false) ?? Accent::DEFAULT;
        $distance = $validator->enum('distance_unit', DistanceUnit::class, true);
        $volume = $validator->enum('volume_unit', VolumeUnit::class, true);
        $consumption = $validator->enum('consumption_unit', ConsumptionUnit::class, true);
        $preferences = PreferenceFields::read($validator, $locales);

        if (
            !$validator->errors()->isEmpty()
            || $displayName === null
            || $theme === null
            || $distance === null
            || $volume === null
            || $consumption === null
            || $preferences['locale'] === null
            || $preferences['timezone'] === null
            || $preferences['currency'] === null
        ) {
            return $validator->errors();
        }

        return new ProfileData($displayName, new DisplayPreferences(
            locale: $preferences['locale'],
            timezone: $preferences['timezone'],
            distanceUnit: $distance,
            volumeUnit: $volume,
            consumptionUnit: $consumption,
            currency: $preferences['currency'],
            theme: $theme,
            accent: $accent,
        ));
    }
}
