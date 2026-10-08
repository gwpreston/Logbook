<?php

declare(strict_types=1);

namespace Logbook\Domain\Issue;

use DateTimeImmutable;

/**
 * An update as the owner typed it on *Add update* (spec.md §7.37 *Updates*).
 */
final readonly class IssueUpdateData
{
    public function __construct(
        /** Calendar date. */
        public DateTimeImmutable $notedOn,
        public ?string $note = null,
        /** Kilometres, when the odometer was noted. */
        public ?string $odometerKm = null,
        /** A status change made with the update, if any. */
        public ?IssueStatus $status = null,
        /** The look-again point when the change is to watching. */
        public ?DateTimeImmutable $lookAgainOn = null,
        public ?string $lookAgainKm = null,
    ) {
    }
}
