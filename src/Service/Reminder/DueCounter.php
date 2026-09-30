<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
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
    ) {
    }

    public function counts(User $user): DueCounts
    {
        $features = $this->features->all();
        if (!$features[Feature::Reminders->value]) {
            return DueCounts::disabled();
        }

        return DueCounts::fromRows(
            $this->reminders->listOpenForCounts($this->access->visibleVehicleIds($user, VehicleScope::Active)),
            LocalTime::today($this->clock, $user->preferences->timeZone()),
            $features,
        );
    }
}
