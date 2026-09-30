<?php

declare(strict_types=1);

namespace Logbook\Support;

use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\I18n\Region;

/**
 * When a vehicle's first MOT (or the local equivalent) usually falls, by
 * the region of the owner's locale (spec.md §7.1 *First MOT due*). Only a
 * suggestion: the owner stores the date they accept, because the rules
 * differ inside a region (Northern Ireland tests at 4 years) and change.
 * The table holds nothing else; a locale with no region, or another
 * region, gets no suggestion.
 */
final class InspectionRules
{
    /** Months from first registration to the first test, per region. */
    public const array MONTHS = [
        'GB' => 36,
        'DE' => 36,
        'FR' => 48,
        'IE' => 48,
        'IT' => 48,
        'ES' => 48,
    ];

    /** The form's hint per region; every other locale gets `vehicle.hint.first_inspection.none`. */
    private const array HINTS = [
        'GB' => 'gb',
        'DE' => 'three_years',
        'FR' => 'four_years',
        'IE' => 'four_years',
        'IT' => 'four_years',
        'ES' => 'four_years',
    ];

    /**
     * Months to the first test for this locale, or null without a rule.
     */
    public static function months(string $locale): ?int
    {
        $region = Region::of($locale);

        return $region === null ? null : (self::MONTHS[$region] ?? null);
    }

    /**
     * The suggested due date: first registration plus the region's months,
     * end-of-month clamped (29 Feb 2024 gives 28 Feb 2027). Null without a
     * rule, and null when it is before $today: that vehicle has had its
     * first test.
     *
     * @param DateTimeImmutable $today the owner's calendar date
     */
    public static function suggest(
        string $locale,
        ?DateTimeImmutable $firstRegistered,
        DateTimeImmutable $today,
    ): ?DateTimeImmutable {
        $months = self::months($locale);
        if ($months === null || $firstRegistered === null) {
            return null;
        }
        $due = LocalTime::addMonths($firstRegistered, $months);

        return $due < $today ? null : $due;
    }

    /**
     * Translation key of the form's hint for this locale.
     */
    public static function hintKey(string $locale): string
    {
        $region = Region::of($locale);

        return 'vehicle.hint.first_inspection.' . ($region === null ? 'none' : (self::HINTS[$region] ?? 'none'));
    }
}
