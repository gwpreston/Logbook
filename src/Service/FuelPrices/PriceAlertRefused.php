<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use RuntimeException;

/**
 * An alert that can't be set: not a favourite linked station
 * (`not_favourite`), a grade it doesn't list (`grade`), a price out of
 * range (`price`) or the 20-alert limit (`limit`).
 */
final class PriceAlertRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function messageKey(): string
    {
        return 'fuel_prices.alert.refused.' . $this->reason;
    }
}
