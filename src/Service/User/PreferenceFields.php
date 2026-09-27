<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Support\Date\LocalTime;
use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\Money\Currency;
use Logbook\Support\Validation\Validator;

/**
 * Validation of the preference fields shared by first-run setup and the
 * settings page: locale, time zone and currency.
 */
final class PreferenceFields
{
    /**
     * @return array{locale: ?string, timezone: ?string, currency: ?string}
     */
    public static function read(Validator $validator, AvailableLocales $locales): array
    {
        $locale = $validator->choice('locale', $locales->formattingLocales(), true);
        $currency = $validator->choice('currency', Currency::SUPPORTED, true);

        $timezone = $validator->string('timezone', true, 64);
        if ($timezone !== null && !LocalTime::isValidTimezone($timezone)) {
            $validator->addError('timezone', 'validation.choice');
            $timezone = null;
        }

        return ['locale' => $locale, 'timezone' => $timezone, 'currency' => $currency];
    }
}
