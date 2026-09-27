<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

enum VehicleType: string
{
    case Car = 'car';
    case Bike = 'bike';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Car => 'directions_car',
            self::Bike => 'two_wheeler',
        };
    }
}
