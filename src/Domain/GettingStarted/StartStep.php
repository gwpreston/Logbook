<?php

declare(strict_types=1);

namespace Logbook\Domain\GettingStarted;

/**
 * The five steps of *Set up your first vehicle* (spec.md §7.9), in order.
 */
enum StartStep: string
{
    case Vehicle = 'vehicle';
    case Mileage = 'mileage';
    case Dates = 'dates';
    case Servicing = 'servicing';
    case Done = 'done';

    public function number(): int
    {
        return match ($this) {
            self::Vehicle => 1,
            self::Mileage => 2,
            self::Dates => 3,
            self::Servicing => 4,
            self::Done => 5,
        };
    }
}
