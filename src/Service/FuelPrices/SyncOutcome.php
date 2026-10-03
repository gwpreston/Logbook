<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * What one sync did, for the job's summary and counts.
 */
final class SyncOutcome
{
    public FeedReport $report;
    public int $added = 0;
    public int $updated = 0;
    public int $removed = 0;
    public int $prices = 0;
    public int $changed = 0;
    public int $unknownStations = 0;
    public int $recorded = 0;
    public int $refreshed = 0;
    public int $alertsSent = 0;
    /** @var array<string, true> refs listed in this run */
    public array $seen = [];

    public function __construct(public readonly bool $full)
    {
        $this->report = new FeedReport();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'full' => $this->full ? 1 : 0,
            'stations' => count($this->seen),
            'added' => $this->added,
            'removed' => $this->removed,
            'prices' => $this->prices,
            'changed' => $this->changed,
            'implausible' => $this->report->implausible,
            'corrected' => $this->report->corrected,
            'skipped' => $this->report->skipped() + $this->unknownStations,
            'history' => $this->recorded,
            'linked' => $this->refreshed,
            'alerts' => $this->alertsSent,
            'requests' => $this->report->requests,
        ];
    }
}
