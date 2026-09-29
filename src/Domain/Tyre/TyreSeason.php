<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * What a tyre is made for (spec.md §7.17). Optional: a tyre with no season
 * is "not specified" and shows no badge, which is how most owners think of
 * their tyres.
 */
enum TyreSeason: string
{
    case Summer = 'summer';
    case Winter = 'winter';
    case AllSeason = 'all_season';
}
