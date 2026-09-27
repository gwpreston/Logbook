<?php

declare(strict_types=1);

namespace Logbook\Service\Maintenance;

/**
 * Which limit of a schedule applies: the months interval (a date) or the
 * distance interval (an odometer reading).
 */
enum DueTrigger: string
{
    case Date = 'date';
    case Distance = 'distance';
}
