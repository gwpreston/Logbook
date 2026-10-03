<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * What a provider read and skipped in one sync (spec.md §7.34).
 */
final class FeedReport
{
    public int $requests = 0;
    public int $stations = 0;
    public int $prices = 0;
    /** Prices outside the plausible range, after the pounds correction. */
    public int $implausible = 0;
    /** Prices in pounds, multiplied by 100. */
    public int $corrected = 0;
    /** Codes the grade map doesn't know. */
    public int $unknownGrades = 0;
    /** Records without the fields they need. */
    public int $invalid = 0;

    public function skipped(): int
    {
        return $this->implausible + $this->unknownGrades + $this->invalid;
    }
}
