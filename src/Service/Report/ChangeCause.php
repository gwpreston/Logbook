<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

/**
 * What a line of *What changed* puts a change down to (spec.md §7.35).
 */
enum ChangeCause: string
{
    /** A part's own change: maintenance, other, or fuel with its details. */
    case Part = 'part';
    /** A fixed part's change in amount, at last year's distance. */
    case Amount = 'amount';
    /** A fixed part's change from driving a different distance. */
    case Distance = 'distance';
    /** Fuel: the change in average price per unit. */
    case Price = 'price';
    /** Fuel: the change in consumption. */
    case Economy = 'economy';
    /** Fuel: an energy bought in only one of the two years. */
    case Energy = 'energy';
    case Payouts = 'payouts';
    /** The lines too small to list one by one. */
    case Small = 'small';
}
