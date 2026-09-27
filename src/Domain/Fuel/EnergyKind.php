<?php

declare(strict_types=1);

namespace Logbook\Domain\Fuel;

/**
 * Liquid fuel (measured in litres) or electricity (kWh). Consumption series
 * are kept separately per kind, so a plug-in hybrid's petrol and charging
 * figures never mix.
 */
enum EnergyKind: string
{
    case Liquid = 'liquid';
    case Electric = 'electric';
}
