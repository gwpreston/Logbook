<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Counts the overdue and due-soon reminders of the vehicles a user can see, per vehicle, with one
 * indexed query (spec.md §8). Rendered on every signed-in page, so it never
 * runs the full reminder sync: see DueCounts::fromRows().
 */
final readonly class DueCounter
{
    public function __construct(
        private ReminderRepository $reminders,
        private FeatureToggles $features,
        private ClockInterface $clock,
        private VehicleAccess $access,
        private VehicleRepository $vehicles,
        private FinanceService $finance,
    ) {
    }

    public function counts(User $user): DueCounts
    {
        $features = $this->features->all();
        if (!$features[Feature::Reminders->value]) {
            return DueCounts::disabled();
        }

        $rows = $this->reminders->listOpenForCounts($this->access->visibleVehicleIds($user, VehicleScope::Active));

        return DueCounts::fromRows(
            $this->withoutHiddenFinance($user, $rows),
            LocalTime::today($this->clock, $user->preferences->timeZone()),
            $features,
        );
    }

    /**
     * Finance reminders count only for those who may see the vehicle's
     * finance (spec.md §7.32 *Access*).
     *
     * @param list<OpenReminderRow> $rows
     * @return list<OpenReminderRow>
     */
    private function withoutHiddenFinance(User $user, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->source->isFinance()) {
                $ids[$row->vehicleId] = true;
            }
        }
        if ($ids === []) {
            return $rows;
        }
        $seen = [];
        foreach ($this->vehicles->listByIds(array_keys($ids)) as $vehicle) {
            $seen[$vehicle->id] = $this->finance->canSee($user, $vehicle);
        }

        return array_values(array_filter(
            $rows,
            static fn (OpenReminderRow $row): bool => !$row->source->isFinance() || ($seen[$row->vehicleId] ?? false),
        ));
    }
}
