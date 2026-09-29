<?php

declare(strict_types=1);

namespace Logbook\Service\Forecast;

use DateTimeImmutable;
use Logbook\Support\Money\Money;

/**
 * Tyres sharing a due point, as the tyre reminder groups them (spec.md
 * §7.18), titled by its helper.
 */
final readonly class TyreDue
{
    public function __construct(
        /** "Tyres: rear due in about 800 mi", in the owner's language. */
        public string $title,
        public bool $overdue,
        public ?DateTimeImmutable $dueOn,
        /** The wear-out odometer (wear only). */
        public ?string $dueKm,
        /** A wear-out date is projected from the average daily distance; an age limit is not. */
        public bool $projected,
        /** The tyres' shares of the records that fitted them, when every one has one and above 0. */
        public ?Money $cost = null,
    ) {
    }
}
