<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

/**
 * The periods true cost is worked out for (spec.md §7.35). The value is the
 * one the card's switch, the widget, the API and the Ask tool use.
 */
enum TrueCostRange: string
{
    case SinceBought = 'since_bought';
    case TwelveMonths = 'last_12_months';
    /** The 12 months before *Last 12 months*: only for the change. */
    case PreviousTwelveMonths = 'previous_12_months';
    case Year = 'year';

    /**
     * From a query or tool argument: the two periods a viewer can choose.
     */
    public static function chosen(mixed $value, self $default = self::TwelveMonths): self
    {
        $range = is_string($value) ? self::tryFrom($value) : null;

        return $range === self::SinceBought || $range === self::TwelveMonths ? $range : $default;
    }
}
