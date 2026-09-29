<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

/**
 * Whether a vehicle's fuel can be estimated (spec.md §7.18).
 */
enum FuelRateStatus: string
{
    case Ready = 'ready';
    /** Under 90 days from the first fill-up in the last 12 months, or no distance driven in them. */
    case NotEnoughFillUps = 'not_enough_fill_ups';
    /** Under a week of mileage history: no average daily distance to project. */
    case NotEnoughMileage = 'not_enough_mileage';
}
