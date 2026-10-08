<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderEntry;
use Logbook\Service\Reminder\ReminderNotFound;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\EntityTag;
use Logbook\Support\Api\JsonInput;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Validation\ValidationErrors;
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
        private EntityTag $tags,
        private ValidationProblem $validation,
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
     * `PATCH /reminders/{id}` (Phase 39.2): a manual reminder's fields laid
     * over it (#283), through the reminder form and `updateManual`, as the
     * Reminders page's *Edit* (`Manage`). Other sources change through
     * what made them: 409 `reminder_not_manual`.
     *
     * @param array<string, mixed> $body
     * @return array{reminder: array<string, mixed>, tag: string}
     * @throws ApiProblem
     */
    public function update(User $user, int $id, array $body, ?string $ifMatch): array
    {
        $reminder = $this->manual($user, $id, $ifMatch);
        $units = new DisplayPreferences(
            $user->preferences->locale,
            $user->preferences->timezone,
            array_key_exists('due_odometer', $body) || array_key_exists('distance_unit', $body)
                ? $user->preferences->distanceUnit
                : DistanceUnit::Kilometre,
            $user->preferences->volumeUnit,
            $user->preferences->consumptionUnit,
            $user->preferences->currency,
        );
        $mapped = JsonInput::reminder($body, $units, $reminder->vehicleId, $reminder->leadTimeDays);
        if ($mapped instanceof ValidationErrors) {
            throw $this->validation->of($mapped);
        }
        $input = JsonInput::overlay(
            ManualReminderForm::values($reminder, $mapped['preferences']),
            $body,
            $mapped['input'],
            JsonInput::REMINDER_FIELDS,
        );
        $data = ManualReminderForm::parse($input, $mapped['preferences'], [$reminder->vehicleId]);
        if ($data instanceof ValidationErrors) {
            throw $this->validation->of(JsonInput::renamed($data, JsonInput::REMINDER_FIELDS));
        }
        $this->reminders->updateManual($user, $reminder, $data);

        return $this->read($user, $id);
    }

    /**
     * `DELETE /reminders/{id}` (Phase 39.2): a manual reminder, as the page's *Delete*.
     *
     * @throws ApiProblem
     */
    public function delete(User $user, int $id, ?string $ifMatch): void
    {
        $this->reminders->deleteManual($this->manual($user, $id, $ifMatch));
    }

    /**
     * The reminder as `GET /reminders` lists it, and the tag of what is stored.
     *
     * @return array{reminder: array<string, mixed>, tag: string}
     */
    private function read(User $user, int $id): array
    {
        $reminder = $this->find($user, $id);
        $vehicle = $this->reminders->vehicleOf($user, $reminder) ?? throw ApiProblem::notFound('There is no such reminder.');
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());

        return [
            'reminder' => Serializer::reminder(new ReminderEntry($reminder, $vehicle), $today),
            'tag' => $this->tags->of($reminder),
        ];
    }

    /**
     * A manual reminder the user may manage, on an active vehicle, as `If-Match` names it.
     *
     * @throws ApiProblem 404, 403, 409 or 412
     */
    private function manual(User $user, int $id, ?string $ifMatch): Reminder
    {
        $reminder = $this->find($user, $id);
        if (!$this->reminders->allows($user, VehicleAbility::Manage, $reminder)) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may see this reminder but may not change it.');
        }
        $vehicle = $this->reminders->vehicleOf($user, $reminder) ?? throw ApiProblem::notFound('There is no such reminder.');
        if ($vehicle->isArchived()) {
            throw new ApiProblem(409, 'vehicle_archived', 'This vehicle is archived; restore it to change its reminders.');
        }
        if ($reminder->source !== ReminderSource::Manual) {
            throw new ApiProblem(
                409,
                'reminder_not_manual',
                'Only a manual reminder is edited or deleted here; this one follows its source (a schedule, a document).',
            );
        }
        if ($ifMatch !== null && !EntityTag::matches($ifMatch, $this->tags->of($reminder))) {
            throw new ApiProblem(
                412,
                'precondition_failed',
                'The reminder changed since it was read (If-Match does not match its ETag); nothing was written.',
            );
        }

        return $reminder;
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
