<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

final readonly class SystemPause implements Pause
{
    public function seconds(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
