<?php

declare(strict_types=1);

namespace Logbook\Service\History;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Vehicle\Vehicle;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One line of the activity feed (spec.md §7.16): an entry of any module, or
 * one of the vehicle's milestones. Quantities are canonical (km, litres or
 * kWh) and amounts canonical decimals in $currency; templates format them.
 */
final readonly class ActivityItem
{
    public function __construct(
        public ActivityKind $kind,
        public Vehicle $vehicle,
        /** The entry's id, for its edit link (the vehicle's id for a milestone). */
        public int $entryId,
        /** The owner's calendar date it happened on (midnight UTC). */
        public DateTimeImmutable $date,
        /** When it was added (UTC): orders the entries of one day. */
        public DateTimeImmutable $createdAt,
        /** What it was, as entered (a title), or '' to use $labelKey. */
        public string $label,
        /** Translation key that names it (category, type, kind or milestone). */
        public string $labelKey,
        public string $icon,
        /** A cost (canonical decimal) in $currency, or null. */
        public ?string $amount = null,
        public ?string $currency = null,
        /** The odometer when the entry has its own reading, in km. */
        public ?string $odometerKm = null,
        /** For a fill-up: its fuel and grade (badge) and volume (litres or kWh). */
        public ?Fuel $fuel = null,
        public ?FuelGrade $grade = null,
        public ?string $volume = null,
        /** Garage or shop (service records). */
        public ?string $vendor = null,
        /** Free text as entered (an expense's or a reading's note). */
        public ?string $note = null,
        /** When a document expires. */
        public ?DateTimeImmutable $expiresOn = null,
        /** For a milestone: which one, and the purchase or sale price (never a cost). */
        public ?Milestone $milestone = null,
        public ?string $price = null,
        /** Number of files attached to the entry. */
        public int $files = 0,
        /**
         * A tyre change's summary; on a service record, the summaries of the
         * tyre changes linked to it (its second line).
         *
         * @var list<TranslatableMessage>
         */
        public array $tyres = [],
    ) {
    }

    /**
     * A single line, not a run of fill-ups (templates tell the two apart).
     */
    public function isRun(): bool
    {
        return false;
    }

    /**
     * Whose files belong to this line (with $entryId as the owner id), or
     * null when it takes none.
     */
    public function filesOwner(): ?AttachmentOwner
    {
        return $this->milestone !== null ? $this->milestone->filesOwner() : $this->kind->filesOwner();
    }

    public function isElectric(): bool
    {
        return $this->fuel?->isElectric() ?? false;
    }

    /**
     * Newest first: by the owner's date, then (milestones) Sold above and
     * First registered / Bought below the day's entries, then by when it was
     * added.
     */
    public static function compare(self $a, self $b): int
    {
        return ($b->date <=> $a->date)
            ?: ($b->rank() <=> $a->rank())
            ?: ($b->createdAt <=> $a->createdAt)
            ?: $b->entryId <=> $a->entryId;
    }

    private function rank(): int
    {
        return $this->milestone?->rank() ?? 0;
    }
}
