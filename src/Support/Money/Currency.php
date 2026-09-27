<?php

declare(strict_types=1);

namespace Logbook\Support\Money;

use NumberFormatter;
use ResourceBundle;

/**
 * ISO 4217 currencies the app offers. Names and minor units come from ICU
 * (`intl`), so they are localised and always correct for the code.
 */
final class Currency
{
    /**
     * Offered in pickers, most common first for the app's typical users.
     * Add codes here to offer more; everything else adapts automatically.
     */
    public const array SUPPORTED = [
        'GBP', 'EUR', 'USD', 'AUD', 'CAD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'ISK', 'PLN', 'CZK', 'HUF',
        'RON', 'BGN', 'TRY', 'JPY', 'CNY', 'HKD', 'TWD', 'KRW', 'SGD', 'MYR', 'THB', 'IDR', 'PHP', 'INR',
        'AED', 'SAR', 'ILS', 'ZAR', 'BRL', 'MXN', 'ARS', 'CLP', 'KWD', 'BHD', 'OMR',
    ];

    public static function isSupported(string $code): bool
    {
        return in_array($code, self::SUPPORTED, true);
    }

    /**
     * Resolve the currency for a vehicle: its own override, else the owner's
     * default, else the app default (`APP_CURRENCY`).
     */
    public static function resolve(?string $vehicleOverride, ?string $userDefault, string $appDefault): string
    {
        foreach ([$vehicleOverride, $userDefault] as $candidate) {
            if ($candidate !== null && self::isSupported($candidate)) {
                return $candidate;
            }
        }

        return $appDefault;
    }

    /**
     * Digits after the decimal point in normal use: GBP 2, JPY 0, BHD 3.
     */
    public static function fractionDigits(string $code): int
    {
        $formatter = new NumberFormatter('en@currency=' . $code, NumberFormatter::CURRENCY);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /**
     * Localised display name, e.g. "British Pound"; the code if ICU has none.
     */
    public static function name(string $code, string $locale): string
    {
        // Regional bundles ("en_GB") hold only overrides; walk up to the language.
        foreach (array_unique([$locale, explode('_', $locale)[0], 'en']) as $candidate) {
            $bundle = ResourceBundle::create($candidate, 'ICUDATA-curr', false);
            $currencies = $bundle instanceof ResourceBundle ? $bundle->get('Currencies') : null;
            $entry = $currencies instanceof ResourceBundle ? $currencies->get($code) : null;
            $name = $entry instanceof ResourceBundle ? $entry->get(1) : null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Localised symbol, e.g. "£", "€", "CHF".
     */
    public static function symbol(string $code, string $locale): string
    {
        $formatter = new NumberFormatter($locale . '@currency=' . $code, NumberFormatter::CURRENCY);
        $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return $symbol !== '' ? $symbol : $code;
    }
}
