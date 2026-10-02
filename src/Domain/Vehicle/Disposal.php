<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

/**
 * Why a vehicle left the garage (spec.md §6 Vehicle, §7.29 *Total loss*,
 * §7.32 *Ending*). A vehicle archived without a reason has none.
 */
enum Disposal: string
{
    case Sold = 'sold';
    /** The insurer settled a total loss: the settlement is the sale price. */
    case WrittenOff = 'written_off';
    /** A PCP handed back (Phase 29.2): the optional final payment is the sale price. */
    case ReturnedLender = 'returned_lender';
    /** A lease ended (Phase 29.2): no sale price. */
    case ReturnedLessor = 'returned_lessor';

    public function labelKey(): string
    {
        return 'vehicle.disposal.' . $this->value;
    }

    /** Handed back at the end of a PCP or lease. */
    public function isReturned(): bool
    {
        return $this === self::ReturnedLender || $this === self::ReturnedLessor;
    }
}
