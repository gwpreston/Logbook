<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\FuelPrices\FeedPrice;
use Logbook\Domain\FuelPrices\FeedStation;

/**
 * Where a provider writes each page it reads, so a sync saves as it goes
 * and a failure part-way keeps what was saved (spec.md §7.34 *Sync job*).
 */
interface FeedSink
{
    /**
     * @param list<FeedStation> $stations
     */
    public function stations(array $stations): void;

    /**
     * @param list<FeedPrice> $prices
     */
    public function prices(array $prices): void;
}
