<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * What a schedule or document calls for today: the reminder row that
 * ReminderSync makes the stored one match.
 */
final readonly class GeneratedReminder
{
    public function __construct(
        public int $vehicleId,
        public ReminderSource $source,
        public int $sourceId,
        /** The source's stable due point; a new value is a new occurrence. */
        public string $occurrence,
        public ?string $category,
        public string $title,
        public ?DateTimeImmutable $dueOn,
        public ?string $dueKm,
        public int $leadTimeDays,
        /** Upcoming, due or overdue. */
        public ReminderStatus $status,
    ) {
    }

    /**
     * Identity of the row: one reminder per schedule or document.
     */
    public function key(): string
    {
        return self::keyOf($this->vehicleId, $this->source, $this->sourceId);
    }

    public static function keyOf(int $vehicleId, ReminderSource $source, ?int $sourceId): string
    {
        return sprintf('%d:%s:%d', $vehicleId, $source->value, $sourceId ?? 0);
    }
}
