<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * A wheel position (spec.md §7.17). Which ones a vehicle has comes from its
 * type (VehicleType::tyrePositions()). The spare counts as fitted but never
 * as rolling, so time on it adds no distance.
 */
enum TyrePosition: string
{
    case FrontLeft = 'fl';
    case FrontRight = 'fr';
    case RearLeft = 'rl';
    case RearRight = 'rr';
    case Spare = 'spare';
    case Front = 'front';
    case Rear = 'rear';

    /**
     * Whether a tyre here turns with the vehicle (everything but the spare).
     */
    public function isRolling(): bool
    {
        return $this !== self::Spare;
    }

    /**
     * The axle a position is on ('front' or 'rear'), null for the spare:
     * "front" in a summary means both front wheels of a car.
     */
    public function axle(): ?string
    {
        return match ($this) {
            self::FrontLeft, self::FrontRight, self::Front => 'front',
            self::RearLeft, self::RearRight, self::Rear => 'rear',
            self::Spare => null,
        };
    }
}
