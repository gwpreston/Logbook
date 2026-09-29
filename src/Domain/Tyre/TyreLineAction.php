<?php

declare(strict_types=1);

namespace Logbook\Domain\Tyre;

/**
 * What one line of a tyre change does to its tyre (spec.md §6
 * TyreChangeLine). `on` and `move` carry the position after the line.
 */
enum TyreLineAction: string
{
    case On = 'on';
    case Off = 'off';
    case Retire = 'retire';
    case Move = 'move';
    case Repair = 'repair';
    /** A tread depth taken (a *Check tread*); the tyre does not move. */
    case Measure = 'measure';

    public function takesPosition(): bool
    {
        return $this === self::On || $this === self::Move;
    }
}
