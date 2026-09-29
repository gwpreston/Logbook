<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

/**
 * Whether a grade could be compared with the vehicle's usual one (spec.md
 * §7.3, *Grade verdict*).
 */
enum GradeVerdictStatus: string
{
    /** A verdict: price premium × economy ratio. */
    case Ok = 'ok';
    /** Either grade has fewer than two single-grade segments. */
    case NotEnoughEconomy = 'not_enough_economy';
    /** Fewer than three fills of the two grades within 30 days of each other. */
    case NotEnoughPricePairs = 'not_enough_price_pairs';
    /** The family has only one grade on this vehicle: nothing to compare. */
    case SingleGrade = 'single_grade';
}
