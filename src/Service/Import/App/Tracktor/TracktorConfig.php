<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

/**
 * The few of Tracktor's `configs` Logbook reads (spec.md §7.13 *The
 * Tracktor reader*), as written: the units every amount was typed in and
 * the currency. Everything else in `configs` is never read.
 */
final readonly class TracktorConfig
{
    public function __construct(
        /** `kilometer` or `mile`; null when absent. */
        public ?string $distance,
        /** `liter` or `gallon` (US or UK not stated). */
        public ?string $volume,
        /** LPG's unit: `liter`, `gallon`, `kilogram` or `pound`. */
        public ?string $lpg,
        /** CNG's unit: the same four. */
        public ?string $cng,
        /** An ISO 4217 code, upper-cased, or null. */
        public ?string $currency,
    ) {
    }

    public function isGallons(): bool
    {
        return $this->volume === 'gallon';
    }

    public function isMiles(): bool
    {
        return $this->distance === 'mile';
    }
}
