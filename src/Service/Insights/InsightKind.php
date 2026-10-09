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
    case FuelSaving = 'fuel_saving';
    case BusinessMileage = 'business_mileage';
    case CheapestToRun = 'cheapest_to_run';
    case Equity = 'equity';
    case EconomyUp = 'economy_up';

    public function icon(): string
    {
        return match ($this) {
            self::ShoppingAround => 'savings',
            self::FuelSaving => 'price_check',
            self::BusinessMileage => 'route',
            self::CheapestToRun => 'leaderboard',
            self::Equity => 'account_balance',
            self::EconomyUp => 'eco',
        };
    }
}
