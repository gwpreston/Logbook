<?php

declare(strict_types=1);

namespace Logbook\Service\Auth;

use Logbook\Domain\User\Username;
use Logbook\Service\User\PreferenceFields;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Units\UnitPreset;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\Validation\Validator;

/**
 * The first-run form: account plus the essential display preferences.
 * Units are chosen as a preset here and fine-tuned later in Settings.
 */
final class SetupForm
{
    /**
     * @param array<array-key, mixed> $input
     */
    public static function parse(array $input, string $locale, AvailableLocales $locales): SetupData|ValidationErrors
    {
        $validator = new Validator($input, $locale);

        $username = $validator->string('username', true, Username::MAX_LENGTH);
        if ($username !== null && !Username::isValid(Username::normalise($username))) {
            $validator->addError('username', 'auth.username_invalid', [
                'min' => Username::MIN_LENGTH,
                'max' => Username::MAX_LENGTH,
            ]);
        }

        $password = $validator->password('password');
        if ($password !== null && ($input['password_confirm'] ?? null) !== $password) {
            $validator->addError('password_confirm', 'auth.password_mismatch');
        }

        $displayName = $validator->string('display_name', false, 100);
        $preferences = PreferenceFields::read($validator, $locales);
        $preset = $validator->enum('units', UnitPreset::class, true);

        if (
            !$validator->errors()->isEmpty()
            || $username === null
            || $password === null
            || $preset === null
            || $preferences['locale'] === null
            || $preferences['timezone'] === null
            || $preferences['currency'] === null
        ) {
            return $validator->errors();
        }

        return new SetupData(
            username: Username::normalise($username),
            password: $password,
            displayName: $displayName ?? $username,
            preferences: new DisplayPreferences(
                locale: $preferences['locale'],
                timezone: $preferences['timezone'],
                distanceUnit: $preset->distance(),
                volumeUnit: $preset->volume(),
                consumptionUnit: $preset->consumption(),
                currency: $preferences['currency'],
                depthUnit: $preset->depth(),
            ),
        );
    }
}
