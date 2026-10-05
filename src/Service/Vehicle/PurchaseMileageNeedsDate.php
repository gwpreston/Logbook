<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use DateTimeImmutable;
use RuntimeException;

/**
 * A vehicle save refused because the *Mileage when bought* would be left
 * without the purchase date it is dated by (spec.md §7.1): typed without
 * the date ("Add the purchase date to record the mileage when bought"), or
 * the date cleared while the mileage is set ("Remove the mileage when
 * bought first, or keep the purchase date"). The same shape as
 * PaperworkNeedsDate.
 */
final class PurchaseMileageNeedsDate extends RuntimeException
{
    public function __construct(
        /** True when the date is being cleared with the reading kept; false for a new mileage without it. */
        public readonly bool $clearing,
    ) {
        parent::__construct('The mileage when bought needs the purchase date.');
    }

    /**
     * The refusal for saving with this purchase date, or null. $stored is
     * whether the vehicle has a `purchase` reading now; $mileage is the
     * mileage given (null: not given, the stored one is kept).
     */
    public static function check(?DateTimeImmutable $purchaseDate, bool $stored, ?PurchaseMileage $mileage): ?self
    {
        if ($purchaseDate !== null) {
            return null;
        }
        $kept = $mileage === null ? $stored : $mileage->km !== null;
        if (!$kept) {
            return null;
        }

        return new self($stored);
    }

    /**
     * The form field the error belongs to: the date being cleared, or the
     * mileage typed without it.
     */
    public function field(): string
    {
        return $this->clearing ? 'purchase_date' : 'purchase_odometer';
    }

    public function messageKey(): string
    {
        return $this->clearing ? 'vehicle.error.purchase_date_has_mileage' : 'vehicle.error.purchase_mileage_needs_date';
    }
}
