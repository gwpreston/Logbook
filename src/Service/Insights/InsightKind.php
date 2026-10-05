<?php

declare(strict_types=1);

namespace Logbook\Service\Insights;

/**
 * The computed insights (spec.md §7.8 *Insights*). Case order is the order
 * they are listed in.
 */
enum InsightKind: string
{
    case ShoppingAround = 'shopping_around';
    case BusinessMileage = 'business_mileage';
    case CheapestToRun = 'cheapest_to_run';
    case Equity = 'equity';

    public function icon(): string
    {
        return match ($this) {
            self::ShoppingAround => 'savings',
            self::BusinessMileage => 'route',
            self::CheapestToRun => 'leaderboard',
            self::Equity => 'account_balance',
        };
    }
}
