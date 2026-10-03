<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Support\Number\Decimal;

/**
 * What effective cost needs to know about a vehicle (spec.md §7.34
 * *Effective cost*): its usual fill (litres) and its consumption over the
 * distance (litres per km, from the last 12 months' full fills, else all
 * time, else none), plus the grade it usually takes.
 */
final readonly class VehicleFuelProfile
{
    /** When a vehicle has no full fills to take a median of. */
    public const string DEFAULT_FILL = '40';

    public function __construct(
        public Vehicle $vehicle,
        /** Litres, canonical. */
        public string $usualFill,
        /** The 40 L default, labelled "assumed". */
        public bool $fillAssumed,
        /** The economy behind litresPerKm: distance and volume, or none. */
        public ?string $economyKm = null,
        public ?string $economyLitres = null,
        /** The economy is all-time (no full fills in the last 12 months). */
        public bool $economyAllTime = false,
        public ?FuelGrade $grade = null,
    ) {
    }

    public function hasEconomy(): bool
    {
        return $this->economyKm !== null && $this->economyLitres !== null
            && Decimal::compare($this->economyKm, '0') > 0;
    }

    /**
     * Litres used over a distance, or null without an economy.
     */
    public function litresFor(float $km): ?string
    {
        if (!$this->hasEconomy()) {
            return null;
        }
        $perKm = Decimal::divide((string) $this->economyLitres, (string) $this->economyKm, 8);

        return Decimal::multiply(Decimal::fromFloat($km, 4), $perKm, 4);
    }
}
