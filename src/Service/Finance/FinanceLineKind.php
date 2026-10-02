<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

/**
 * What a derived cost line is (spec.md §7.32 *Costs*).
 */
enum FinanceLineKind: string
{
    /** A payment's interest share (HP, PCP, loan). */
    case Interest = 'interest';
    /** A documentation or option-to-purchase fee. */
    case Fee = 'fee';
    /** A lease rental, the initial one included. */
    case Rental = 'rental';
    /** The difference that makes an ended agreement's lines its exact cost of credit. */
    case Adjustment = 'adjustment';
}
