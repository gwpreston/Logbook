<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

/**
 * Why a period has no cost per distance (spec.md §7.35, §7.7's rules).
 */
enum TrueCostGap: string
{
    /** No distance driven, or a mileage log that starts after the period does. */
    case NoMileage = 'no_mileage';
    /** Nothing logged: not £0 a mile. */
    case NoCosts = 'no_costs';
    /** Under 90 days. */
    case TooShort = 'too_short';

    public function messageKey(): string
    {
        return 'true_cost.gap.' . $this->value;
    }
}
