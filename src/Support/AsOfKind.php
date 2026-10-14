<?php

declare(strict_types=1);

namespace Logbook\Support;

/**
 * What an "as of" date is the date of (spec.md §8 *Show the working*).
 */
enum AsOfKind: string
{
    /** A valuation, or the sale that replaced it. */
    case Valuation = 'valuation';
    /** The lender's settlement quote. */
    case Quote = 'quote';
    /** A tread measurement. */
    case Measurement = 'measurement';
    /** The last time the same work was done. */
    case LastTime = 'last_time';
    /** A price the provider reported. */
    case Prices = 'prices';
    /** The span of driving and prices an estimate is projected from. */
    case Window = 'window';

    public function labelKey(): string
    {
        return 'as_of.kind.' . $this->value;
    }
}
