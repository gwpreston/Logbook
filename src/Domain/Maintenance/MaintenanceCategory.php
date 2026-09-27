<?php

declare(strict_types=1);

namespace Logbook\Domain\Maintenance;

/**
 * What kind of work a maintenance entry or schedule is. Stored as the code,
 * so adding a category needs no migration; anything not covered is `other`
 * with a descriptive title.
 */
enum MaintenanceCategory: string
{
    case Service = 'service';
    case Oil = 'oil';
    case Tyres = 'tyres';
    case Brakes = 'brakes';
    case Battery = 'battery';
    case Repair = 'repair';
    case Bodywork = 'bodywork';
    case Other = 'other';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Service => 'build',
            self::Oil => 'oil_barrel',
            self::Tyres => 'tire_repair',
            self::Brakes => 'stop_circle',
            self::Battery => 'battery_charging_full',
            self::Repair => 'car_repair',
            self::Bodywork => 'format_paint',
            self::Other => 'handyman',
        };
    }
}
