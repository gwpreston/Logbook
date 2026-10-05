<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

/**
 * The vehicle form's *Mileage when bought* (spec.md §7.1), as given on a
 * save: the vehicle's `purchase` reading in km, or null to remove it.
 * Passing no PurchaseMileage at all keeps the stored reading (moved with
 * the purchase date), as every save that is not the vehicle form does.
 */
final readonly class PurchaseMileage
{
    public function __construct(
        /** Canonical km; null = none. */
        public ?string $km,
    ) {
    }
}
