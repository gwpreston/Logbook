<?php

declare(strict_types=1);

namespace Logbook\Domain\Odometer;

/**
 * Where an odometer reading came from. Readings from fill-ups, maintenance
 * entries, compliance documents, tyre changes and incidents are owned by
 * that entry and change with it; the `purchase` reading (Phase 33.3) is the
 * vehicle form's *Mileage when bought*, owned by the vehicle itself.
 */
enum OdometerSource: string
{
    case Manual = 'manual';
    case Fuel = 'fuel';
    case Maintenance = 'maintenance';
    case Document = 'document';
    case Tyre = 'tyre';
    case Incident = 'incident';
    case Purchase = 'purchase';

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
            self::Purchase => 'key',
        };
    }
}
