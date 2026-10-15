<?php

declare(strict_types=1);

namespace Logbook\Domain\Part;

/**
 * When a part's warranty period starts (spec.md §7.39, #427): a supplier's
 * runs from the sale, a garage's from the fitting. A distance limit always
 * counts from the first fitting's mileage.
 */
enum WarrantyStart: string
{
    case Purchase = 'purchase';
    case Fitting = 'fitting';

    public function labelKey(): string
    {
        return 'part.warranty_from.' . $this->value;
    }
}
