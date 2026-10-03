<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use RuntimeException;

/**
 * A link that can't be made: prices are off (`off`), the provider station
 * is unknown (`unknown`), or it is linked to another station (`taken`).
 */
final class LinkRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function messageKey(): string
    {
        return 'fuel_prices.link.refused.' . $this->reason;
    }
}
