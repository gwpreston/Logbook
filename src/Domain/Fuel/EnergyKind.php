<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

/**
 * Liquid fuel (measured in litres), electricity (kWh) or compressed natural
 * gas (kg, Phase 31). Consumption series are kept separately per kind, so a
 * plug-in hybrid's petrol and charging figures, or a bi-fuel car's petrol
 * and CNG, never mix.
 */
enum EnergyKind: string
{
    case Liquid = 'liquid';
    case Electric = 'electric';
    case Gas = 'gas';

    /**
     * Whether quantities follow the user's volume unit (litres, gallons).
     * kWh and kg are the same in every unit system.
     */
    public function followsVolumeUnit(): bool
    {
        return $this === self::Liquid;
    }
}
