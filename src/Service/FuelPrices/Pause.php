<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * Waits between a feed's requests (its rate limit); a no-op in tests.
 */
interface Pause
{
    public function seconds(float $seconds): void;
}
