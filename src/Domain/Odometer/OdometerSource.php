<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

/**
 * Where an odometer reading came from. Readings from fill-ups, maintenance
 * entries, compliance documents and tyre changes are owned by that entry
 * and change with it.
 */
enum OdometerSource: string
{
    case Manual = 'manual';
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Tyre = 'tyre';
    case Incident = 'incident';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Manual => 'speed',
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
            self::Document => 'verified_user',
            self::Tyre => 'tire_repair',
            self::Incident => 'car_crash',
        };
    }
}
