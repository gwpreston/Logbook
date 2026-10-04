<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;

enum FuelType: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Electric = 'ev';
    case Hybrid = 'hybrid';
    case Phev = 'phev';
    case Lpg = 'lpg';
    /** Compressed natural gas (Phase 31), usually bi-fuel with petrol. */
    case Cng = 'cng';
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
     * The kind of energy of the vehicle's usual fuel, which its capacity is
     * measured in and its headline economy follows.
     */
    public function primaryKind(): EnergyKind
    {
        return $this->fittingFamilies()[0]->kind();
    }

    /**
     * What the capacity field holds: the battery for an electric vehicle,
     * the gas tank in kg for CNG, else the fuel tank (a plug-in hybrid's
     * battery is not recorded).
     */
    public function capacityLabelKey(): string
    {
        return match ($this) {
            self::Electric => 'vehicle.field.capacity_battery',
            self::Cng => 'vehicle.field.capacity_gas',
            default => 'vehicle.field.capacity_tank',
        };
    }

    /**
     * The fill-up families that fit this vehicle (spec.md §7.3), its usual
     * one first: petrol only for a self-charging or mild hybrid, petrol and
     * electricity for a plug-in hybrid, CNG and petrol for a CNG car (they
     * are almost all bi-fuel), else its own family. The picker
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
            self::Cng => [Fuel::Cng, Fuel::Petrol],
            self::Other => [Fuel::Other],
        };
    }
}
