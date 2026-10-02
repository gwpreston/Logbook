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
    /**
     * A finance payment marked missed with no later payment (Phase 29.2): a
     * *Now* item, so it ranks with overdue work, before every check.
     */
    case FinanceMissed = 'finance_missed';
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
    /** Liquid fuel economy worse over the recent tanks than the year (Phase 25). */
    case DriftLiquid = 'drift_liquid';
    /** The same for electricity: its own kind, so a plug-in hybrid can hide each. */
    case DriftElectric = 'drift_electric';
    /** A fill-up's price far from nearby ones of the same grade (Phase 25). */
    case FuelPrice = 'fuel_price';
    /** A maintenance record far above its category's usual (Phase 25). */
    case MaintenanceCost = 'maintenance_cost';
    /** A claim waiting for news for more than 30 days (Phase 27.1, §7.29). */
    case StalledClaim = 'stalled_claim';
    /** A PCP or lease heading more than 2% over its mileage allowance (Phase 29.2, item 11). */
    case FinanceMileage = 'finance_mileage';

    public function severity(): AttentionSeverity
    {
        return $this === self::Overdue || $this === self::FinanceMissed ? AttentionSeverity::Now : AttentionSeverity::Check;
    }

    /**
     * Whether *Hide* applies. Economy flags are confirmed with *Looks
     * right*, trips are fixed in the data, overdue work is dismissed
     * through its reminder.
     */
    public function isHideable(): bool
    {
        return !in_array($this, [self::Overdue, self::Economy, self::TripsExceed, self::FinanceMissed], true);
    }

    /**
     * The drift kinds, one per series.
     */
    public function isDrift(): bool
    {
        return $this === self::DriftLiquid || $this === self::DriftElectric;
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
