<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Closure;
use DateTimeImmutable;
use Logbook\Domain\Fuel\FuelGrade;

/**
 * A provider whose whole list is downloaded and searched on the server
 * (spec.md §7.34): the user's position never leaves it.
 */
interface BulkPriceProvider extends PriceProvider
{
    /**
     * Download stations, then prices, page by page into the sink. With
     * $since, only what changed after it (an incremental sync); without,
     * everything (a full sync).
     *
     * @param array<string, FuelGrade> $gradeMap from gradeMap()
     * @param Closure(): bool $cancelled
     * @throws FeedFailure when the feed cannot be read; what reached the sink stays
     */
    public function sync(
        ProviderCredentials $credentials,
        array $gradeMap,
        ?DateTimeImmutable $since,
        FeedSink $sink,
        Closure $cancelled,
    ): FeedReport;
}
