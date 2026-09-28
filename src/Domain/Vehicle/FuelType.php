<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

use Logbook\Domain\Fuel\Fuel;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Electric = 'ev';
    case Hybrid = 'hybrid';
    case Phev = 'phev';
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

    /**
     * What the capacity field holds: the battery for an electric vehicle,
     * else the fuel tank (a plug-in hybrid's battery is not recorded).
     */
    public function capacityLabelKey(): string
    {
        return $this->isElectric() ? 'vehicle.field.capacity_battery' : 'vehicle.field.capacity_tank';
    }

    /**
     * The fill-up families that fit this vehicle (spec.md §7.3), its usual
     * one first: petrol only for a self-charging or mild hybrid, petrol and
     * electricity for a plug-in hybrid, else its own family. The picker
     * offers the rest under *Other fuels*.
     *
     * @return non-empty-list<Fuel>
     */
    public function fittingFamilies(): array
    {
        return match ($this) {
            self::Petrol, self::Hybrid => [Fuel::Petrol],
            self::Phev => [Fuel::Petrol, Fuel::Electricity],
            self::Diesel => [Fuel::Diesel],
            self::Electric => [Fuel::Electricity],
            self::Lpg => [Fuel::Lpg],
            self::Other => [Fuel::Other],
        };
    }
}
