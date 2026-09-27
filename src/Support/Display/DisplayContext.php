<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use Logbook\Support\Config\AppSettings;

/**
 * The display preferences in force for the current request.
 *
 * Like the translator's locale, this is set once per request by
 * LocaleMiddleware (from the signed-in user, or the app defaults) and read by
 * the formatter and the Twig helpers, so templates never pass preferences
 * around by hand.
 */
final class DisplayContext
{
    private DisplayPreferences $preferences;

    public function __construct(private readonly AppSettings $settings)
    {
        $this->preferences = $this->defaults($settings->locale);
    }

    public function preferences(): DisplayPreferences
    {
        return $this->preferences;
    }

    public function apply(DisplayPreferences $preferences): void
    {
        $this->preferences = $preferences;
    }

    /**
     * App defaults (metric, APP_TIMEZONE, APP_CURRENCY) in the given locale.
     */
    public function defaults(string $locale): DisplayPreferences
    {
        return DisplayPreferences::defaults($locale, $this->settings->timezone, $this->settings->currency);
    }
}
