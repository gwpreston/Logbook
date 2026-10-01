<?php

declare(strict_types=1);

namespace Logbook\Domain\Attention;

/**
 * What a *Needs attention* item is about (spec.md §7.24). Case order is the
 * list's order; the value of a hideable kind is stored in
 * `attention_hidden.kind`.
 */
enum AttentionKind: string
{
    /** *Coming up*'s overdue group (§7.18). */
    case Overdue = 'overdue';
    /** A reading the Mileage tab flags (§7.2). */
    case Reading = 'reading';
    /** The vehicle's unconfirmed economy flags, as one item (§7.3). */
    case Economy = 'economy';
    /** No reading for too long where projections need one. */
    case MileageStale = 'mileage_stale';
    /** Business trips add up to more than the mileage log (§7.22). */
    case TripsExceed = 'trips_exceed';
    /** The latest valuation is too old (§7.1). */
    case ValuationStale = 'valuation_stale';

    public function severity(): AttentionSeverity
    {
        return $this === self::Overdue ? AttentionSeverity::Now : AttentionSeverity::Check;
    }

    /**
     * Whether *Hide* applies. Economy flags are confirmed with *Looks
     * right*, trips are fixed in the data, overdue work is dismissed
     * through its reminder.
     */
    public function isHideable(): bool
    {
        return $this === self::Reading || $this === self::MileageStale || $this === self::ValuationStale;
    }

    /**
     * The hideable kinds, by their stored value.
     */
    public static function hideable(string $value): ?self
    {
        $kind = self::tryFrom($value);

        return $kind !== null && $kind->isHideable() ? $kind : null;
    }

    /**
     * Position in the list: case order.
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
