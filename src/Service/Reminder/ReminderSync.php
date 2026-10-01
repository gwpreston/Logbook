<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Compliance\DocumentState;
use Logbook\Service\Compliance\FirstInspection;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Tyre\TyreReminderTitle;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\User\UserDirectory;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\UserDisplayScope;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reconciles the stored reminders of the vehicles a user can see with their sources and with today
 * (spec.md §7.6 Sync): adds, updates and deletes generated reminders, and
 * moves every open reminder to the status today calls for. Writes only rows
 * that differ, so running it on every read is cheap. Each vehicle is judged
 * by its owner's lead times and today (Phase 19), so a shared vehicle's
 * reminders never depend on who looked.
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
        private TyreService $tyres,
        private TyreReminderTitle $tyreTitles,
        private VehicleAccess $access,
        private UserDirectory $directory,
        private UserDisplayScope $scope,
        private TranslatorInterface $translator,
    ) {
    }

    public function sync(User $user): void
    {
        $all = $this->access->visibleVehicleIds($user, VehicleScope::All);
        $active = $this->access->visibleVehicleIds($user, VehicleScope::Active);

        $existing = [];
        foreach ($this->reminders->listGeneratedForVehicles($all) as $reminder) {
            $existing[GeneratedReminder::keyOf($reminder->vehicleId, $reminder->source, $reminder->sourceId)] = $reminder;
        }

        // A switched-off module's reminders are left exactly as they are (not
        // generated, not deleted), so switching it back on loses nothing.
        $enabled = $this->features->all();
        $withSchedules = $enabled[Feature::Maintenance->value];
        $withDocuments = $enabled[Feature::Compliance->value];
        $withTyres = $enabled[Feature::Tyres->value];
        foreach ($existing as $key => $reminder) {
            $feature = $reminder->source->feature();
            if ($feature !== null && !$enabled[$feature->value]) {
                unset($existing[$key]);
            }
        }

        // Archived vehicles raise nothing, so their reminders fall out below.
        $owners = [];
        $vehicles = [];
        foreach ($this->vehicles->listByIds($active) as $vehicle) {
            $vehicles[$vehicle->id] = $vehicle;
            $owner = $vehicle->userId === $user->id ? $user : $this->directory->find($vehicle->userId) ?? $user;
            $owners[$vehicle->id] = $owner;
            $today = LocalTime::today($this->clock, $owner->preferences->timeZone());
            $preferences = $this->settings->reminderPreferences($owner->id);
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
                $list = array_map(static fn (DocumentState $s) => $s->document, $documents);
                $firstDue = FirstInspection::pending($vehicle, $list);
                if ($firstDue !== null) {
                    $wanted[] = ReminderGenerator::fromFirstInspection(
                        $vehicle->id,
                        $firstDue,
                        $today,
                        $this->firstInspectionTitle($owner),
                        $preferences,
                    );
                } elseif (FirstInspection::hasCertificate($list)) {
                    $this->closeFirstInspection($existing, $vehicle->id);
                }
            }
            if ($withTyres) {
                $verdict = $this->tyres->verdict($vehicle, $owner);
                $tyres = $verdict->isJudgeable() ? ReminderGenerator::fromTyres(
                    $vehicle->id,
                    $verdict,
                    $this->tyres->latestChangeId($vehicle),
                    $this->tyreTitles->title($owner, $vehicle, $verdict),
                    $preferences,
                ) : null;
                if ($tyres !== null) {
                    $wanted[] = $tyres;
                }
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

        $histories = [];
        foreach ($this->reminders->listOpenManualForVehicles($active) as $manual) {
            if ($manual->dueOn === null && $manual->dueKm === null) {
                continue;
            }
            $owner = $owners[$manual->vehicleId] ?? $user;
            $today = LocalTime::today($this->clock, $owner->preferences->timeZone());
            $history = null;
            if ($manual->dueKm !== null && isset($vehicles[$manual->vehicleId])) {
                $history = $histories[$manual->vehicleId] ??= $this->odometer->history($vehicles[$manual->vehicleId]);
            }
            $status = ReminderRules::manual(
                $manual->dueOn,
                $manual->dueKm,
                $today,
                $manual->leadTimeDays,
                $history?->latest()?->readingKm,
                $history?->averageKmPerDay(),
                $this->settings->reminderPreferences($owner->id)->scheduleKm,
            )->status;
            if ($status !== $manual->status) {
                $this->reminders->setStatus($manual->id, $status, $this->clock->now());
            }
        }
    }

    /**
     * The first certificate has been logged: the first MOT reminder is done
     * and kept, not deleted as an orphan (spec.md §7.6 *First MOT*). The
     * certificate's own expiry reminder takes over.
     *
     * @param array<string, Reminder> $existing taken out of, so it is not deleted
     */
    private function closeFirstInspection(array &$existing, int $vehicleId): void
    {
        $key = GeneratedReminder::keyOf($vehicleId, ReminderSource::FirstInspection, $vehicleId);
        $stored = $existing[$key] ?? null;
        if ($stored === null) {
            return;
        }
        unset($existing[$key]);
        if ($stored->status !== ReminderStatus::Done) {
            $this->reminders->setStatus($stored->id, ReminderStatus::Done, $this->clock->now());
        }
    }

    /**
     * "First MOT", in the owner's language whoever asks.
     */
    private function firstInspectionTitle(User $owner): string
    {
        return $this->scope->run($owner, fn (): string => $this->translator->trans('compliance.first_inspection.title'));
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
