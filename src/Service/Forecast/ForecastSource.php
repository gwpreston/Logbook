<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

/**
 * Where a *Coming up* item comes from (spec.md §7.18).
 */
enum ForecastSource: string
{
    case Schedule = 'schedule';
    case Document = 'document';
    case Tyres = 'tyres';
    /** A vehicle's *First MOT due* date before its first certificate (Phase 21.2). */
    case FirstInspection = 'first_inspection';
    case Reminder = 'reminder';
    /** An active finance agreement's payments, or its final payment (Phase 29.2). */
    case Finance = 'finance';
}
