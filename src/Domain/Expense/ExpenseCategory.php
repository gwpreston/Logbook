<?php

declare(strict_types=1);

namespace Logbook\Domain\Expense;

/**
 * What an ad-hoc expense was for. Stored as the code, so adding a category
 * needs no migration. Fuel, maintenance and documents are not categories
 * here: their costs roll up from their own entries (spec.md §7.7).
 */
enum ExpenseCategory: string
{
    /** Road or vehicle tax. */
    case Tax = 'tax';
    case Parking = 'parking';
    /** Tolls, congestion and clean-air charges, ferries. */
    case Tolls = 'tolls';
    case Cleaning = 'cleaning';
    /** Parts and gear that are not maintenance: a phone mount, a helmet. */
    case Accessories = 'accessories';
    case Fines = 'fines';
    /**
     * Loan interest, lease and PCP payments (Phase 14.2). With a purchase
     * price, only the interest and fees: the payments that pay off the
     * price would count the car twice.
     */
    case Finance = 'finance';
    case Other = 'other';

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Tax => 'account_balance',
            self::Parking => 'local_parking',
            self::Tolls => 'toll',
            self::Cleaning => 'local_car_wash',
            self::Accessories => 'shopping_bag',
            self::Fines => 'gavel',
            self::Finance => 'credit_card',
            self::Other => 'receipt_long',
        };
    }
}
