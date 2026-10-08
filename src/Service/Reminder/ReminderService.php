<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Domain\Feature\Feature;
use DateTimeZone;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Webhook\WebhookEvents;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * Reminders as the owner sees and handles them (spec.md §7.6): the grouped
 * list, dismiss / mark done / reopen, and manual reminders. Every read
 * syncs first, so what is shown is always current. Reminders raised by a
 * switched-off module (maintenance, documents) are left out of every list,
 * and so are never sent either (spec.md §7.10).
 */
final readonly class ReminderService
{
    public function __construct(
        private ReminderRepository $reminders,
        private VehicleRepository $vehicles,
        private ReminderSync $sync,
        private ClockInterface $clock,
        private FeatureToggles $features,
        private VehicleAccess $access,
        private OdometerService $odometer,
        private ReminderSettingsStore $settings,
        private UserDirectory $directory,
        private FinanceService $finance,
        private WebhookEvents $webhooks,
        private IssueService $issues,
    ) {
    }

    /**
     * @param bool $recipientOnly only the vehicles whose reminders the user
     *                            receives (the calendar feed, spec.md §7.6)
     */
    public function overview(User $user, bool $recipientOnly = false): ReminderOverview
    {
        $this->sync->sync($user);
        $active = $recipientOnly
            ? $this->access->recipientVehicleIds($user)
            : $this->access->visibleVehicleIds($user, VehicleScope::Active);
        $entries = $this->entries($user, $this->reminders->listForVehicles($active));

        return new ReminderOverview(
            $entries['open'],
            $entries['closed'],
            LocalTime::today($this->clock, $user->preferences->timeZone()),
        );
    }

    /**
     * @param list<Reminder> $reminders
     * @return array{open: list<ReminderEntry>, closed: list<ReminderEntry>}
     */
    public function entries(User $user, array $reminders): array
    {
        $vehicles = [];
        foreach ($this->vehicles->listByIds($this->access->visibleVehicleIds($user, VehicleScope::All)) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
        }

        $enabled = $this->features->all();
        $open = [];
        $closed = [];
        foreach ($reminders as $reminder) {
            $vehicle = $vehicles[$reminder->vehicleId] ?? null;
            $feature = $reminder->source->feature();
            if ($vehicle === null || ($feature !== null && !$enabled[$feature->value])) {
                continue;
            }
            if ($reminder->source->isFinance() && !$this->finance->canSee($user, $vehicle)) {
                continue;
            }
            $entry = new ReminderEntry($reminder, $vehicle);
            if ($reminder->status->isOpen()) {
                $open[] = $entry;
            } else {
                $closed[] = $entry;
            }
        }

        usort($open, self::compareOpen(...));
        usort($closed, static fn (ReminderEntry $a, ReminderEntry $b): int
            => ($b->reminder->closedAt <=> $a->reminder->closedAt) ?: $b->reminder->id <=> $a->reminder->id);

        return ['open' => $open, 'closed' => $closed];
    }

    /**
     * @throws ReminderNotFound
     */
    public function get(User $user, int $id): Reminder
    {
        $reminder = $this->reminders->findById($id);
        $visible = $this->access->visibleVehicleIds($user, VehicleScope::All);
        if ($reminder === null || !in_array($reminder->vehicleId, $visible, true)) {
            throw new ReminderNotFound(sprintf('Reminder %d not found.', $id));
        }
        if ($reminder->source->isFinance()) {
            $vehicle = $this->vehicles->findById($reminder->vehicleId);
            if ($vehicle === null || !$this->finance->canSee($user, $vehicle)) {
                throw new ReminderNotFound(sprintf('Reminder %d not found.', $id));
            }
        }

        return $reminder;
    }

    /**
     * Whether the user may do this with the reminder's vehicle.
     */
    public function allows(User $user, VehicleAbility $ability, Reminder $reminder): bool
    {
        $vehicle = $this->vehicles->findById($reminder->vehicleId);

        return $vehicle !== null && $this->access->can($user, $ability, $vehicle);
    }

    public function vehicleOf(User $user, Reminder $reminder): ?Vehicle
    {
        $vehicle = $this->vehicles->findById($reminder->vehicleId);

        return $vehicle !== null && $this->access->can($user, VehicleAbility::View, $vehicle) ? $vehicle : null;
    }

    public function dismiss(Reminder $reminder): void
    {
        $this->reminders->setStatus($reminder->id, ReminderStatus::Dismissed, $this->clock->now());
        $this->webhooks->reminder($reminder->vehicleId, $reminder->id, 'dismissed');
    }

    public function markDone(Reminder $reminder): void
    {
        $this->reminders->setStatus($reminder->id, ReminderStatus::Done, $this->clock->now());
        // With `issues` off its data is left as it is (spec.md §7.10).
        $issue = $reminder->source === ReminderSource::Issue ? $reminder->sourceId : null;
        if ($issue !== null && $this->features->isEnabled(Feature::Issues)) {
            $this->lookedAt($reminder->vehicleId, $issue);
        }
        $this->webhooks->reminder($reminder->vehicleId, $reminder->id, 'done');
    }

    /**
     * Undo dismiss / done: the status today calls for applies again. What
     * was already sent is remembered, so reopening never re-sends it.
     */
    public function reopen(User $user, Reminder $reminder): void
    {
        if ($reminder->status->isOpen()) {
            return;
        }

        $this->reminders->setStatus($reminder->id, ReminderStatus::Upcoming, $this->clock->now());
        $this->webhooks->reminder($reminder->vehicleId, $reminder->id, 'reopened');
        $this->sync->sync($user);
    }

    /**
     * *Done* on a look-again reminder is *Looked at it* (spec.md §7.37, #311):
     * the point is cleared and the issue stays watching, dated in the owner's
     * zone. The next sync removes the reminder with its point.
     */
    private function lookedAt(int $vehicleId, int $issueId): void
    {
        $vehicle = $this->vehicles->findById($vehicleId);
        $issue = $vehicle === null ? null : $this->issues->find($vehicle, $issueId);
        if ($vehicle === null || $issue === null) {
            return;
        }
        $owner = $this->directory->find($vehicle->userId);
        $zone = $owner?->preferences->timeZone() ?? new DateTimeZone('UTC');
        $this->issues->lookedAt($vehicle, $issue, $zone);
    }

    public function createManual(User $user, ManualReminderData $data): Reminder
    {
        $id = $this->reminders->insertManual($data, $this->statusFor($user, $data), $this->clock->now());
        $this->webhooks->reminder($data->vehicleId, $id, 'created');

        return $this->get($user, $id);
    }

    /**
     * Save changes to a manual reminder. Moving its due date is a new
     * occurrence: it opens again and will be notified afresh.
     */
    public function updateManual(User $user, Reminder $reminder, ManualReminderData $data): Reminder
    {
        $newOccurrence = $reminder->dueOn != $data->dueOn || !self::sameKm($reminder->dueKm, $data->dueKm);
        $status = $newOccurrence
            ? $this->statusFor($user, $data)
            : ReminderRules::next($reminder->status, $this->statusFor($user, $data));

        $this->reminders->updateManual($reminder->id, $data, $status, $newOccurrence, $this->clock->now());
        $this->webhooks->reminder($reminder->vehicleId, $reminder->id, 'updated');

        return $this->get($user, $reminder->id);
    }

    public function deleteManual(Reminder $reminder): void
    {
        if ($reminder->source !== ReminderSource::Manual) {
            throw new LogicException('Only manual reminders can be deleted; the others follow their source.');
        }

        $this->reminders->delete($reminder->id);
        $this->webhooks->reminder($reminder->vehicleId, $reminder->id, 'deleted');
    }

    /**
     * Judged by the vehicle owner's today and lead distance, as sync judges
     * it (spec.md §7.6), so saving and the next sync agree.
     */
    private function statusFor(User $user, ManualReminderData $data): ReminderStatus
    {
        $vehicle = $this->vehicles->findById($data->vehicleId);
        $owner = $vehicle === null || $vehicle->userId === $user->id
            ? $user
            : ($this->directory->find($vehicle->userId) ?? $user);
        $today = LocalTime::today($this->clock, $owner->preferences->timeZone());
        $history = $data->dueKm === null || $vehicle === null ? null : $this->odometer->history($vehicle);

        return ReminderRules::manual(
            $data->dueOn,
            $data->dueKm,
            $today,
            $data->leadTimeDays,
            $history?->latest()?->readingKm,
            $history?->averageKmPerDay(),
            $this->settings->reminderPreferences($owner->id)->scheduleKm,
        )->status;
    }

    private static function sameKm(?string $a, ?string $b): bool
    {
        return ($a === null || $b === null) ? $a === $b : Decimal::compare($a, $b) === 0;
    }

    /**
     * Most urgent first; then by due date (none last), then oldest first.
     */
    private static function compareOpen(ReminderEntry $a, ReminderEntry $b): int
    {
        $x = $a->reminder;
        $y = $b->reminder;

        return ($x->status->urgency() <=> $y->status->urgency())
            ?: match (true) {
                $x->dueOn === null && $y->dueOn === null => 0,
                $x->dueOn === null => 1,
                $y->dueOn === null => -1,
                default => $x->dueOn <=> $y->dueOn,
            }
            ?: $x->id <=> $y->id;
    }
}
