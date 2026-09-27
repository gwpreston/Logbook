<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

/**
 * Where an odometer reading came from. Readings from fill-ups (and, later,
 * maintenance) are owned by that entry and change with it.
 */
enum OdometerSource: string
{
    case Manual = 'manual';
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Manual => 'speed',
            self::Fuel => 'local_gas_station',
            self::Maintenance => 'build',
        };
    }
}
