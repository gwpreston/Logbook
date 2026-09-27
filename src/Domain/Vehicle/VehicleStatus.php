<?php

declare(strict_types=1);

namespace Logbook\Domain\Vehicle;

enum VehicleStatus: string
{
    case Active = 'active';
    /** Sold or off the road: hidden from active views, history kept. */
    case Archived = 'archived';
}
