<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderNotFound;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * The reminder actions (spec.md §7.20 *Reminder actions*, Phase 39.1): the
 * Reminders page's *Done*, *Dismiss* and *Reopen*, through the same
 * service, with the same ability (`Log` on the reminder's vehicle) and on a
 * reminder of any source. An action already in effect writes nothing and
 * says `"unchanged": true`, so a retry is safe.
 */
final readonly class ApiReminders
{
    public const array ACTIONS = ['done', 'dismiss', 'reopen'];

    public function __construct(
        private ReminderService $reminders,
        private ClockInterface $clock,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param value-of<self::ACTIONS> $action
     * @param VehicleAbility $ability the route's (the page's: `Log`)
     * @return array<string, mixed> the reminder as `GET /reminders` lists it, plus `unchanged`
     * @throws ApiProblem 404 unseen, 403 without the ability, 409 on an archived vehicle
     */
    public function act(User $user, int $id, string $action, VehicleAbility $ability = VehicleAbility::Log): array
    {
        $reminder = $this->find($user, $id);
        if (!$this->reminders->allows($user, $ability, $reminder)) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may see this reminder but may not change it.');
        }
        $vehicle = $this->reminders->vehicleOf($user, $reminder) ?? throw ApiProblem::notFound('There is no such reminder.');
        if ($vehicle->isArchived()) {
            throw new ApiProblem(409, 'vehicle_archived', 'This vehicle is archived; restore it to change its reminders.');
        }

        $unchanged = match ($action) {
            'done' => $reminder->status === ReminderStatus::Done,
            'dismiss' => $reminder->status === ReminderStatus::Dismissed,
            'reopen' => $reminder->status->isOpen(),
        };
        if (!$unchanged) {
            match ($action) {
                'done' => $this->reminders->markDone($reminder),
                'dismiss' => $this->reminders->dismiss($reminder),
                'reopen' => $this->reminders->reopen($user, $reminder),
            };
            $reminder = $this->find($user, $id);
        }
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return Serializer::reminder(new ReminderEntry($reminder, $vehicle), $today) + ['unchanged' => $unchanged];
    }

    /**
     * A reminder the user can see whose source module is on: one from a
     * switched-off module is left out of the list, so it isn't found here.
     */
    private function find(User $user, int $id): Reminder
    {
        try {
            $reminder = $this->reminders->get($user, $id);
        } catch (ReminderNotFound) {
            throw ApiProblem::notFound('There is no such reminder.');
        }
        $feature = $reminder->source->feature();
        if ($feature !== null && !$this->features->isEnabled($feature)) {
            throw ApiProblem::notFound('There is no such reminder.');
        }

        return $reminder;
    }
}
