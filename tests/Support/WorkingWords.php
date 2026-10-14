<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Kernel;
use Logbook\Service\Report\TrueCostWording;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Units\UnitPreset;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A translator and formatter for the working builders' unit tests
 * (Phase 48.2): English, UK units, pounds, London time.
 */
final class WorkingWords
{
    public static function translator(string $locale = 'en'): TranslatorInterface
    {
        return TranslatorFactory::create(Kernel::rootDir() . '/translations', $locale, null, false);
    }

    public static function context(UnitPreset $preset = UnitPreset::Uk, string $locale = 'en'): DisplayContext
    {
        $settings = AppSettings::fromEnv(new Env(['APP_TIMEZONE' => 'UTC']), '/app');
        $context = new DisplayContext($settings);
        $context->apply(new DisplayPreferences(
            $locale === 'de' ? 'de_DE' : 'en_GB',
            'Europe/London',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'GBP',
        ));

        return $context;
    }

    public static function formatter(UnitPreset $preset = UnitPreset::Uk, string $locale = 'en'): DisplayFormatter
    {
        return new DisplayFormatter(self::context($preset, $locale), self::translator($locale));
    }

    /**
     * How true cost is written, in the same words and units as the formatter.
     */
    public static function trueCostWording(UnitPreset $preset = UnitPreset::Uk, string $locale = 'en'): TrueCostWording
    {
        $context = self::context($preset, $locale);
        $translator = self::translator($locale);

        return new TrueCostWording($translator, new DisplayFormatter($context, $translator), $context);
    }
}
