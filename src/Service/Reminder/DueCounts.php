<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Reminder\ReminderStatus;

/**
 * An owner's overdue and due-soon reminders counted per vehicle (spec.md §8):
 * the sidebar badge and dots, and the garage and dashboard "N due" badges.
 */
final readonly class DueCounts
{
    /**
     * @param array<int, VehicleDueCount> $vehicles keyed by vehicle id; vehicles with nothing due are absent
     * @param bool $enabled false while the reminders module is switched off (no badges, no dots)
     */
    private function __construct(
        private array $vehicles,
        public bool $enabled,
    ) {
    }

    public static function disabled(): self
    {
        return new self([], false);
    }

    /**
     * Count from the stored open reminders. The stored status is refreshed
     * whenever reminders are read (ReminderSync), so between syncs the date
     * is checked again against $today: it can only make a reminder more
     * urgent (a schedule may be overdue by distance before its date).
     *
     * @param list<OpenReminderRow> $rows open reminders of active vehicles
     * @param array<string, bool> $features module → enabled (FeatureToggles::all())
     */
    public static function fromRows(array $rows, DateTimeImmutable $today, array $features): self
    {
        $vehicles = [];
        foreach ($rows as $row) {
            $feature = $row->source->feature();
            if ($feature !== null && !($features[$feature->value] ?? true)) {
                continue;
            }
            $status = self::effectiveStatus($row, $today);
            if (!$status->isNotifiable()) {
                continue;
            }
            $vehicles[$row->vehicleId] = ($vehicles[$row->vehicleId] ?? new VehicleDueCount())
                ->add($status === ReminderStatus::Overdue);
        }

        return new self($vehicles, true);
    }

    public static function effectiveStatus(OpenReminderRow $row, DateTimeImmutable $today): ReminderStatus
    {
        if ($row->dueOn === null || $row->status->isClosed()) {
            return $row->status;
        }
        $byDate = ReminderRules::statusForDate($row->dueOn, $today, $row->leadTimeDays);

        return $byDate->urgency() < $row->status->urgency() ? $byDate : $row->status;
    }

    public function forVehicle(int $vehicleId): VehicleDueCount
    {
        return $this->vehicles[$vehicleId] ?? new VehicleDueCount();
    }

    /**
     * Every vehicle's overdue + due soon: the Reminders badge.
     */
    public function total(): int
    {
        return array_sum(array_map(static fn (VehicleDueCount $c): int => $c->total(), $this->vehicles));
    }
}
