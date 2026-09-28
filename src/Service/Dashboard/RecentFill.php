<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Fuel\FillEconomy;

/**
 * A fill-up on the recent fuel widget, with its vehicle and currency.
 */
final readonly class RecentFill
{
    public function __construct(
        public Vehicle $vehicle,
        public FillEconomy $fill,
        public string $currency,
    ) {
    }
}
