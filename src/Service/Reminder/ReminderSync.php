<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

/**
 * Reconciles an owner's stored reminders with their sources and with today
 * (spec.md §7.6 Sync): adds, updates and deletes generated reminders, and
 * moves every open reminder to the status today calls for. Writes only rows
 * that differ, so running it on every read is cheap.
 */
final readonly class ReminderSync
{
    public function __construct(
        private VehicleRepository $vehicles,
        private ScheduleService $schedules,
        private ComplianceService $compliance,
        private OdometerService $odometer,
        private ReminderRepository $reminders,
        private ReminderSettingsStore $settings,
        private ClockInterface $clock,
        private FeatureToggles $features,
    ) {
    }

    public function sync(User $user): void
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $preferences = $this->settings->reminderPreferences($user->id);

        $existing = [];
        foreach ($this->reminders->listGeneratedForUser($user->id) as $reminder) {
            $existing[GeneratedReminder::keyOf($reminder->vehicleId, $reminder->source, $reminder->sourceId)] = $reminder;
        }

        // A switched-off module's reminders are left exactly as they are (not
        // generated, not deleted), so switching it back on loses nothing.
        $enabled = $this->features->all();
        $withSchedules = $enabled[Feature::Maintenance->value];
        $withDocuments = $enabled[Feature::Compliance->value];
        foreach ($existing as $key => $reminder) {
            $feature = $reminder->source->feature();
            if ($feature !== null && !$enabled[$feature->value]) {
                unset($existing[$key]);
            }
        }

        // Archived vehicles raise nothing, so their reminders fall out below.
        foreach ($this->vehicles->listForUser($user->id, false) as $vehicle) {
            $wanted = [];
            if ($withSchedules) {
                $schedules = $this->schedules->states(
                    $vehicle,
                    $today,
                    $this->odometer->history($vehicle),
                    $preferences->scheduleDays,
                    $preferences->scheduleKm,
                );
                $wanted = ReminderGenerator::fromSchedules($vehicle->id, $schedules, $preferences);
            }
            if ($withDocuments) {
                $documents = $this->compliance->states($vehicle, $today, $preferences->documentDays);
                $wanted = [...$wanted, ...ReminderGenerator::fromDocuments($vehicle->id, $documents, $today, $preferences)];
            }

            foreach ($wanted as $generated) {
                $stored = $existing[$generated->key()] ?? null;
                unset($existing[$generated->key()]);
                $stored === null ? $this->insert($generated) : $this->update($stored, $generated);
            }
        }

        foreach ($existing as $orphan) {
            $this->reminders->delete($orphan->id);
        }

        foreach ($this->reminders->listOpenManualForUser($user->id) as $manual) {
            if ($manual->dueOn === null) {
                continue;
            }
            $status = ReminderRules::statusForDate($manual->dueOn, $today, $manual->leadTimeDays);
            if ($status !== $manual->status) {
                $this->reminders->setStatus($manual->id, $status, $this->clock->now());
            }
        }
    }

    private function insert(GeneratedReminder $generated): void
    {
        try {
            $this->reminders->insertGenerated($generated, $this->clock->now());
        } catch (UniqueConstraintViolationException) {
            // A concurrent sync (the scheduled task and a page view) added it
            // first; the next sync reconciles anything that differs.
        }
    }

    private function update(Reminder $stored, GeneratedReminder $generated): void
    {
        $newOccurrence = $stored->occurrence !== $generated->occurrence;
        $status = $newOccurrence ? $generated->status : ReminderRules::next($stored->status, $generated->status);

        $unchanged = !$newOccurrence
            && $status === $stored->status
            && $stored->title === $generated->title
            && $stored->category === $generated->category
            && $stored->dueOn == $generated->dueOn
            && $stored->dueKm === $generated->dueKm
            && $stored->leadTimeDays === $generated->leadTimeDays;
        if ($unchanged) {
            return;
        }

        $this->reminders->updateGenerated($stored->id, $generated, $status, $newOccurrence, $this->clock->now());
    }
}
