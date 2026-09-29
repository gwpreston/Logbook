<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

/**
 * Where a vehicle's ownership period starts (spec.md §7.7 *Cost of
 * ownership*).
 */
enum OwnershipStart: string
{
    /** On the purchase date. */
    case Purchase = 'purchase';
    /** Without a purchase date: the first ledger line or reading, whichever is earlier. */
    case FirstLogged = 'first_logged';
}
