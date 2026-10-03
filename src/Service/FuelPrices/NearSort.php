<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * *Cheapest near me*'s order (spec.md §7.34): effective cost by default.
 */
enum NearSort: string
{
    case Effective = 'effective';
    case Price = 'price';
    case Distance = 'distance';
}
