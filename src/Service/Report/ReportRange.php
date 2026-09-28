<?php

declare(strict_types=1);

namespace Logbook\Service\Report;

/**
 * The periods a report can cover (spec.md §7.7). The URL value is the case
 * value (`?range=12m`).
 */
enum ReportRange: string
{
    case ThisMonth = 'month';
    case ThreeMonths = '3m';
    case TwelveMonths = '12m';
    case ThisYear = 'ytd';
    case AllTime = 'all';
    case Custom = 'custom';

    public const self DEFAULT = self::TwelveMonths;

    /**
     * The presets offered as chips (custom has its own date fields).
     *
     * @return list<self>
     */
    public static function presets(): array
    {
        return [self::ThisMonth, self::ThreeMonths, self::TwelveMonths, self::ThisYear, self::AllTime];
    }
}
