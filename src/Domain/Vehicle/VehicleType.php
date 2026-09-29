<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

use Logbook\Domain\Tyre\TyrePosition;

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

    /**
     * The wheel positions of this type, in display order (spec.md §7.17):
     * the one list the tyre forms, the Tyres tab and validation use.
     *
     * @return list<TyrePosition>
     */
    public function tyrePositions(): array
    {
        return match ($this) {
            self::Car => [
                TyrePosition::FrontLeft,
                TyrePosition::FrontRight,
                TyrePosition::RearLeft,
                TyrePosition::RearRight,
                TyrePosition::Spare,
            ],
            self::Bike => [TyrePosition::Front, TyrePosition::Rear],
        };
    }
}
