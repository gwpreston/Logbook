<?php

declare(strict_types=1);

namespace Logbook\Service\Issue;

use DateTimeImmutable;

/**
 * A look-again point as *Watch* takes it (spec.md §7.37): a date and/or an
 * odometer (canonical km); both may be empty.
 */
final readonly class LookAgain
{
    public function __construct(
        public ?DateTimeImmutable $on = null,
        public ?string $km = null,
    ) {
    }
}
