<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;

/**
 * A provider asked per search (spec.md §7.34): the search position and
 * radius are sent to its host, which an admin acknowledges first. None
 * ships yet; the interface is here for later adapters.
 */
interface AreaPriceProvider extends PriceProvider
{
    /**
     * Stations and prices within $km of a point, written to the sink.
     *
     * @param array<string, FuelGrade> $gradeMap
     * @throws FeedFailure
     */
    public function search(
        ProviderCredentials $credentials,
        array $gradeMap,
        float $latitude,
        float $longitude,
        float $km,
        FeedSink $sink,
    ): FeedReport;
}
