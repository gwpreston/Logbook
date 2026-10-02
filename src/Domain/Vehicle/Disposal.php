<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

/**
 * Why a vehicle left the garage (spec.md §6 Vehicle, §7.29 *Total loss*).
 * A vehicle archived without a reason has none.
 */
enum Disposal: string
{
    case Sold = 'sold';
    /** The insurer settled a total loss: the settlement is the sale price. */
    case WrittenOff = 'written_off';
}
