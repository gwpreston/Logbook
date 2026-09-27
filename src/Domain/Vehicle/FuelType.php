<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Electric = 'ev';
    case Hybrid = 'hybrid';
    case Lpg = 'lpg';
    case Other = 'other';

    /**
     * Electric vehicles store battery capacity in kWh rather than a tank
     * volume in litres.
     */
    public function isElectric(): bool
    {
        return $this === self::Electric;
    }
}
