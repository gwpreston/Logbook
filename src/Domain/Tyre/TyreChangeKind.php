<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * What a tyre change was (spec.md §7.17). Each kind has its own form; every
 * kind but a repair needs an odometer, because distance per tyre is built
 * from the odometer at each change.
 */
enum TyreChangeKind: string
{
    case Existing = 'existing';
    case Fit = 'fit';
    case Swap = 'swap';
    case Rotate = 'rotate';
    case Repair = 'repair';
    case Remove = 'remove';
    /** Tread depths measured (Phase 11.2); nothing moves. */
    case Check = 'check';

    public function requiresOdometer(): bool
    {
        return $this !== self::Repair;
    }

    /**
     * Whether the form offers Cost / Garage (and so may write a service
     * record). Tyres already on the vehicle and a rotation cost nothing new.
     */
    public function takesCost(): bool
    {
        return $this === self::Fit || $this === self::Swap || $this === self::Repair || $this === self::Remove;
    }

    /**
     * Whether it may link a `tyres` service record: every kind but a tread
     * check, which is a measurement, not work done.
     */
    public function takesLink(): bool
    {
        return $this !== self::Check;
    }

    /**
     * Whether the form takes tread depths (spec.md §7.17): every kind but a
     * rotation and a repair.
     */
    public function takesDepth(): bool
    {
        return $this !== self::Rotate && $this !== self::Repair;
    }

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Existing, self::Fit => 'tire_repair',
            self::Swap => 'inventory_2',
            self::Rotate => 'restart_alt',
            self::Repair => 'build',
            self::Remove => 'archive',
            self::Check => 'fact_check',
        };
    }
}
