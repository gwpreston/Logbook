<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use Locale;
use ResourceBundle;

/**
 * The region of a locale ("en_GB" → "GB"), for what differs by country: the
 * fuel grades offered first, the sale pack's MOT history line.
 */
final class Region
{
    /**
     * Upper case, or null when the locale names none.
     */
    public static function of(string $locale): ?string
    {
        $region = Locale::getRegion($locale);

        return is_string($region) && $region !== '' ? strtoupper($region) : null;
    }

    /**
     * The two-letter code of a three-letter one ("GBR" → "GB"), from ICU's
     * own table; null when it isn't a country ICU knows (Phase 31: Fuelio's
     * stations carry three letters).
     */
    public static function fromAlpha3(string $code): ?string
    {
        /** @var array<string, string>|null $map */
        static $map = null;
        if ($map === null) {
            $map = [];
            $bundle = ResourceBundle::create('supplementalData', 'ICUDATA', false);
            $mappings = $bundle?->get('codeMappings');
            if ($mappings instanceof ResourceBundle) {
                foreach ($mappings as $entry) {
                    $two = $entry instanceof ResourceBundle ? $entry->get(0) : null;
                    $three = $entry instanceof ResourceBundle ? $entry->get(2) : null;
                    if (is_string($two) && is_string($three) && preg_match('/^[A-Z]{2}$/', $two) === 1) {
                        $map[$three] = $two;
                    }
                }
            }
        }

        return $map[strtoupper(trim($code))] ?? null;
    }
}
