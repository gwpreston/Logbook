<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

/**
 * Whether depreciation can be worked out (spec.md §7.1).
 */
enum DepreciationState: string
{
    /** No purchase price: "Add what you paid to see depreciation". */
    case NoPurchasePrice = 'no_purchase_price';
    /** A price but nothing to compare it with: "Add a valuation…". */
    case NoValue = 'no_value';
    case Ready = 'ready';
}
