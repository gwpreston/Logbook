<?php

declare(strict_types=1);

namespace Logbook\Service\Dashboard;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;

/**
 * One line of the recent activity widget (spec.md §7.8).
 */
final readonly class ActivityItem
{
    public function __construct(
        public ActivityKind $kind,
        public Vehicle $vehicle,
        /** The entry's id, for its edit link. */
        public int $entryId,
        /** The owner's calendar date it happened on. */
        public DateTimeImmutable $date,
        /** When it was added (UTC): orders the entries of one day. */
        public DateTimeImmutable $createdAt,
        /** What it was, as entered (a title), or '' to use $labelKey. */
        public string $label,
        /** Translation key that names it when it has no title. */
        public string $labelKey,
        public string $icon,
        /** Amount (canonical decimal) in $currency, or null. */
        public ?string $amount = null,
        public ?string $currency = null,
        /** For a reading: the odometer, in km. */
        public ?string $readingKm = null,
    ) {
    }

    /**
     * Newest first: by the owner's date, then by when it was added.
     */
    public static function compare(self $a, self $b): int
    {
        return ($b->date <=> $a->date)
            ?: ($b->createdAt <=> $a->createdAt)
            ?: $b->entryId <=> $a->entryId;
    }
}
