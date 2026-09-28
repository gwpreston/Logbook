<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Counts an owner's overdue and due-soon reminders per vehicle with one
 * indexed query (spec.md §8). Rendered on every signed-in page, so it never
 * runs the full reminder sync: see DueCounts::fromRows().
 */
final readonly class DueCounter
{
    public function __construct(
        private ReminderRepository $reminders,
        private FeatureToggles $features,
        private ClockInterface $clock,
    ) {
    }

    public function counts(User $user): DueCounts
    {
        $features = $this->features->all();
        if (!$features[Feature::Reminders->value]) {
            return DueCounts::disabled();
        }

        return DueCounts::fromRows(
            $this->reminders->listOpenForCounts($user->id),
            LocalTime::today($this->clock, $user->preferences->timeZone()),
            $features,
        );
    }
}
