<?php

declare(strict_types=1);

namespace Logbook\Domain\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceCategory;

/**
 * A reminder (spec.md §6 Reminder): generated from a schedule or document,
 * or added by hand. Dates are calendar dates (midnight UTC); instants are UTC.
 */
final readonly class Reminder
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public ReminderSource $source,
        /** The schedule or document; the vehicle for tyres; null for a manual reminder. */
        public ?int $sourceId,
        /** The source's due point this reminder was raised for (generated only). */
        public ?string $occurrence,
        /** Schedule category or document type code. */
        public ?string $category,
        /** Empty for an untitled document (its type names it). */
        public string $title,
        public ?string $notes,
        /** Null only for a distance-only schedule that cannot be placed on the calendar yet. */
        public ?DateTimeImmutable $dueOn,
        /** Odometer reading it is due at, km (schedules). */
        public ?string $dueKm,
        public int $leadTimeDays,
        public ReminderStatus $status,
        /** The status last sent out, if any. */
        public ?ReminderStatus $notifiedStatus,
        /** @var list<string> keys of the channels that delivered it */
        public array $channelsNotified,
        public ?DateTimeImmutable $lastNotifiedAt,
        public ?DateTimeImmutable $closedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Icon name in the vendored sprite: the schedule's category or the
     * document's type; a bell for manual reminders.
     */
    public function icon(): string
    {
        $category = $this->category ?? '';

        return match ($this->source) {
            ReminderSource::Schedule => MaintenanceCategory::tryFrom($category)?->icon() ?? 'build',
            ReminderSource::Compliance => ComplianceType::tryFrom($category)?->icon() ?? 'description',
            ReminderSource::Tyre => 'tire_repair',
            ReminderSource::Manual => 'notifications',
        };
    }

    /**
     * Whether its current status still has to be sent out.
     */
    public function awaitsNotification(): bool
    {
        return $this->status->isNotifiable() && $this->notifiedStatus !== $this->status;
    }
}
