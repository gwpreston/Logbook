<?php

declare(strict_types=1);

namespace Logbook\Domain\FuelPrices;

/**
 * How a price provider is asked (spec.md §7.34 *Providers*).
 */
enum ProviderKind: string
{
    /** The whole country is downloaded on a schedule and searched locally. */
    case Bulk = 'bulk';
    /** Asked per search with a position and radius. */
    case Area = 'area';
}
