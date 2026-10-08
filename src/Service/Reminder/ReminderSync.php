<?php

declare(strict_types=1);

namespace Logbook\Service\Reminder;

use Logbook\Service\Webhook\WebhookEvents;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Logbook\Domain\Access\VehicleScope;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Domain\Reminder\ReminderStatus;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\IssueRepository;
use Logbook\Domain\Issue\IssueStatus;
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
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayFormatter;
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
        private FinanceAgreementRepository $agreements,
        private VehicleService $vehicleService,
        private DisplayFormatter $formatter,
        private WebhookEvents $webhooks,
        private IssueRepository $issues,
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
        $withFinance = $enabled[Feature::Finance->value];
        $withIssues = $enabled[Feature::Issues->value];
        $watched = [];
        if ($withIssues) {
            foreach ($this->issues->listForVehicles($active, [IssueStatus::Watching]) as $issue) {
                $watched[$issue->vehicleId][] = $issue;
            }
        }
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

            if ($withFinance) {
                foreach ($this->agreements->listForVehicle($vehicle->id) as $agreement) {
                    if (!$agreement->status->isActive()) {
                        $this->closeFinance($existing, $vehicle->id, $agreement->id);
                        continue;
                    }
                    [$finalTitle, $endTitle] = $this->financeTitles($owner, $vehicle, $agreement);
                    $wanted = [...$wanted, ...ReminderGenerator::fromFinance(
                        $vehicle->id,
                        $agreement,
                        $today,
                        $finalTitle,
                        $endTitle,
                        $preferences,
                    )];
                }
            }

            // Phase 40.1: watching issues' look-again points (#311).
            if (isset($watched[$vehicle->id])) {
                $history = $this->odometer->history($vehicle);
                foreach ($watched[$vehicle->id] as $issue) {
                    $look = ReminderGenerator::fromIssue(
                        $vehicle->id,
                        $issue,
                        $this->issueTitle($owner, $issue->data->title),
                        $today,
                        $preferences,
                        $history->latest()?->readingKm,
                        $history->averageKmPerDay(),
                    );
                    if ($look !== null) {
                        $wanted[] = $look;
                    }
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
                $this->told($manual->vehicleId, $manual->id, $status);
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
            $this->told($vehicleId, $stored->id, ReminderStatus::Done);
        }
    }

    /**
     * An ended agreement's finance reminders are done and kept, not deleted
     * as orphans (spec.md §7.32 *Reminders*). Ending marks them done
     * straight away; this keeps one closed if the sync saw it first.
     *
     * @param array<string, Reminder> $existing taken out of, so they are not deleted
     */
    private function closeFinance(array &$existing, int $vehicleId, int $agreementId): void
    {
        foreach ([ReminderSource::Finance, ReminderSource::FinanceEnd] as $source) {
            $key = GeneratedReminder::keyOf($vehicleId, $source, $agreementId);
            $stored = $existing[$key] ?? null;
            if ($stored === null) {
                continue;
            }
            unset($existing[$key]);
            if (!$stored->status->isClosed()) {
                $this->reminders->setStatus($stored->id, ReminderStatus::Done, $this->clock->now());
                $this->told($vehicleId, $stored->id, ReminderStatus::Done);
            }
        }
    }

    /**
     * "Final payment of £9,450 (Toyota Financial Services)" and "Agreement
     * ends: decide what to do (…)", in the owner's language and currency
     * whoever asks.
     *
     * @return array{0: string, 1: string}
     */
    private function financeTitles(User $owner, Vehicle $vehicle, FinanceAgreement $agreement): array
    {
        $currency = $this->vehicleService->currencyFor($owner, $vehicle);

        return $this->scope->run($owner, function () use ($agreement, $currency): array {
            $data = $agreement->data;
            $amount = $data->finalPayment === null ? '' : $this->formatter->money($data->finalPayment, $currency);

            $final = $this->translator->trans('finance.reminder.final', ['amount' => $amount, 'lender' => $data->lender]);
            $ends = $this->translator->trans('finance.reminder.ends', ['lender' => $data->lender]);

            // A long lender's name must still fit the title column.
            return [mb_substr($final, 0, 150), mb_substr($ends, 0, 150)];
        });
    }

    /**
     * "Look again: Brake pipes corroded", in the owner's language whoever asks.
     */
    private function issueTitle(User $owner, string $title): string
    {
        return $this->scope->run(
            $owner,
            fn (): string => mb_substr($this->translator->trans('issue.reminder_title', ['title' => $title]), 0, 150),
        );
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
            $id = $this->reminders->insertGenerated($generated, $this->clock->now());
            $this->told($generated->vehicleId, $id, $generated->status);
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
        if ($status !== $stored->status) {
            $this->told($stored->vehicleId, $stored->id, $status);
        }
    }

    /**
     * Entry webhooks hear when a reminder becomes due, overdue or done
     * (spec.md §7.20 *Webhooks*): once, as the status is stored.
     */
    private function told(int $vehicleId, int $reminderId, ReminderStatus $status): void
    {
        if (in_array($status, [ReminderStatus::Due, ReminderStatus::Overdue, ReminderStatus::Done], true)) {
            $this->webhooks->reminder($vehicleId, $reminderId, $status->value);
        }
    }
}
