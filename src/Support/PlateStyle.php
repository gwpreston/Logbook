<?php

declare(strict_types=1);

namespace Logbook\Support;

use Logbook\Support\I18n\Region;

/**
 * How a registration plate is drawn (spec.md §8 *Registration plate*): the
 * UK rear plate for an owner in GB, a neutral plate for everyone else. Only
 * a look; nothing is stored.
 */
enum PlateStyle: string
{
    case Gb = 'gb';
    case Neutral = 'neutral';

    /**
     * The style for a locale's region: GB gets the UK plate; another
     * region, a locale with none ("en", "de") or an unreadable one, the
     * neutral plate.
     */
    public static function forLocale(string $locale): self
    {
        return Region::of($locale) === 'GB' ? self::Gb : self::Neutral;
    }

    /**
     * What the plate shows: trimmed, upper case, each run of whitespace one
     * space. Never re-spaced or validated, so a personalised plate shows as
     * typed. Empty for a blank registration (the plate is then left out).
     */
    public static function text(?string $registration): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $registration ?? '')));
    }
}
