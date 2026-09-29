<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use Locale;

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
}
