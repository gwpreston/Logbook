<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use Logbook\Domain\Webhook\WebhookKind;
use Logbook\Domain\Webhook\WebhookEvent;
use Logbook\Service\Webhook\WebhookEvents;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Reminder\Reminder;
use Logbook\Domain\Reminder\ReminderSource;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\ReminderRepository;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Forecast\FinanceDue;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\Depreciation;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finance agreements (spec.md §7.32): who may see them, their figures as of
 * the vehicle owner's today, and changes to them.
 *
 * Access is `Manage` and `ViewCosts` with the module on; anyone else gets
 * FinanceAgreementNotFound (404). The owner's today, not the viewer's,
 * decides which payments are paid, so every viewer sees the same schedule
 * and totals (#125).
 */
final readonly class FinanceService
{
    public function __construct(
        private FinanceAgreementRepository $agreements,
        private ValuationRepository $valuations,
        private ExpenseEntryRepository $expenses,
        private VehicleRepository $vehicleRows,
        private VehicleService $vehicles,
        private OdometerService $odometer,
        private ExpenseService $expenseEntries,
        private ReminderRepository $reminders,
        private TranslatorInterface $translator,
        private UserDirectory $directory,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private ClockInterface $clock,
        private WebhookEvents $webhooks,
    ) {
    }

    /**
     * Whether the user may see and change the vehicle's finance: the module
     * on, `Manage` and `ViewCosts`.
     */
    public function canSee(User $user, Vehicle $vehicle): bool
    {
        return $this->features->isEnabled(Feature::Finance)
            && $this->access->can($user, VehicleAbility::Manage, $vehicle)
            && $this->access->can($user, VehicleAbility::ViewCosts, $vehicle);
    }

    /**
     * @throws FinanceAgreementNotFound when the user may not see finance
     */
    public function assertCanSee(User $user, Vehicle $vehicle): void
    {
        if (!$this->canSee($user, $vehicle)) {
            throw new FinanceAgreementNotFound();
        }
    }

    /**
     * The vehicle owner's today, which judges what is paid.
     */
    public function ownerToday(User $viewer, Vehicle $vehicle): DateTimeImmutable
    {
        return LocalTime::today($this->clock, $this->ownerZone($viewer, $vehicle));
    }

    /**
     * The vehicle owner's time zone, which dates readings for the mileage.
     */
    public function ownerZone(User $viewer, Vehicle $vehicle): DateTimeZone
    {
        $owner = $vehicle->userId === $viewer->id ? $viewer : ($this->directory->find($vehicle->userId) ?? $viewer);

        return $owner->preferences->timeZone();
    }

    /**
     * @return list<FinanceAgreement> the active one first, then newest first
     */
    public function forVehicle(User $user, Vehicle $vehicle): array
    {
        $this->assertCanSee($user, $vehicle);

        return $this->agreements->listForVehicle($vehicle->id);
    }

    public function active(Vehicle $vehicle): ?FinanceAgreement
    {
        return $this->agreements->activeFor($vehicle->id);
    }

    /**
     * @throws FinanceAgreementNotFound
     */
    public function get(User $user, Vehicle $vehicle, int $id): FinanceAgreement
    {
        $this->assertCanSee($user, $vehicle);

        return $this->agreements->find($vehicle->id, $id) ?? throw new FinanceAgreementNotFound();
    }

    /**
     * An agreement with its figures, events, quotes, checks and overlaps.
     */
    public function view(User $user, Vehicle $vehicle, FinanceAgreement $agreement): AgreementView
    {
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $events = $this->agreements->eventsFor($agreement->id);
        $quotes = $this->agreements->quotesFor($agreement->id);
        $today = $this->ownerToday($user, $vehicle);
        $schedule = Schedule::of($agreement, $events, $today);
        $valuation = $this->latestValuation($vehicle, $today);
        $figures = AgreementFigures::of($agreement, $schedule, $quotes, $valuation, $today, $currency);
        $zone = $this->ownerZone($user, $vehicle);
        $mileage = MileageAllowance::of($agreement, $this->odometer->history($vehicle), $today, $zone, $currency);

        return new AgreementView(
            agreement: $agreement,
            figures: $figures,
            events: $events,
            quotes: $quotes,
            checks: FinanceCheck::of($agreement->data),
            overlap: FinanceLedger::overlapping($agreement, $schedule, $this->expenses->listForVehicle($vehicle->id)),
            currency: $currency,
            mileage: $mileage,
        );
    }

    /**
     * The Finance tab (spec.md §7.32 *Finance tab*): the given agreement, or
     * without one the active agreement, in the prototype's cards; the
     * purchase reading and current value for its Purchase and Value & equity
     * cards; and the earlier agreements below.
     *
     * @throws FinanceAgreementNotFound for someone who may not see finance
     */
    public function page(User $user, Vehicle $vehicle, ?FinanceAgreement $agreement = null): FinancePage
    {
        $agreements = $this->forVehicle($user, $vehicle);
        $active = array_find($agreements, static fn (FinanceAgreement $a): bool => $a->status->isActive());
        $shown = $agreement ?? $active;
        $view = $shown === null ? null : $this->view($user, $vehicle, $shown);
        $earlier = [];
        foreach ($agreements as $other) {
            if (!$other->status->isActive() && $other->id !== $shown?->id) {
                $earlier[] = $this->view($user, $vehicle, $other);
            }
        }
        // Each missed mark by the date it concerns, for its *Undo*.
        $marks = [];
        foreach ($view === null ? [] : $view->events as $event) {
            if ($event->kind === PaymentEventKind::Missed && $event->dueOn !== null) {
                $marks[$event->dueOn->format('Y-m-d')] = $event->id;
            }
        }

        return new FinancePage(
            shown: $view,
            earlier: $earlier,
            canAdd: !$vehicle->isArchived() && $active === null,
            purchaseReading: $this->odometer->purchaseReading($vehicle),
            currentValue: Depreciation::currentValue($vehicle, $this->valuations->listForVehicle($vehicle->id)),
            marks: $marks,
            today: $this->ownerToday($user, $vehicle),
            open: $shown !== null && self::isOpen($shown) && !$vehicle->isArchived(),
        );
    }

    /**
     * The active agreement's view, for the overview card; null without one
     * or without access.
     */
    public function activeView(User $user, Vehicle $vehicle): ?AgreementView
    {
        if (!$this->canSee($user, $vehicle)) {
            return null;
        }
        $agreement = $this->agreements->activeFor($vehicle->id);

        return $agreement === null ? null : $this->view($user, $vehicle, $agreement);
    }

    /**
     * The active agreement's payments still due, for *Coming up* (spec.md
     * §7.32 *Coming up*): for anyone who may see the vehicle's costs, plain
     * below `Manage` (#128). Null with the module off, without costs or
     * without an active agreement.
     */
    public function forecastDue(User $user, Vehicle $vehicle): ?FinanceDue
    {
        if (!$this->features->isEnabled(Feature::Finance) || !$this->access->can($user, VehicleAbility::ViewCosts, $vehicle)) {
            return null;
        }
        $agreement = $this->agreements->activeFor($vehicle->id);
        if ($agreement === null) {
            return null;
        }
        $schedule = Schedule::of($agreement, $this->agreements->eventsFor($agreement->id), $this->ownerToday($user, $vehicle));
        $due = array_values(array_filter(
            $schedule->payments,
            static fn (ScheduledPayment $payment): bool => $payment->status === PaymentStatus::Due,
        ));

        return new FinanceDue($agreement->id, $due, !$this->access->can($user, VehicleAbility::Manage, $vehicle));
    }

    /**
     * The agreement the archive page offers choices for (spec.md §7.32
     * *Archive page*, #126): the active one, else the latest one handed back
     * or ended (to archive as returned once it has ended). Null without one
     * or without access.
     */
    public function archiveAgreement(User $user, Vehicle $vehicle): ?FinanceAgreement
    {
        if ($vehicle->isArchived() || !$this->canSee($user, $vehicle)) {
            return null;
        }
        $agreements = $this->agreements->listForVehicle($vehicle->id);
        $latest = $agreements[0] ?? null;
        if ($latest === null) {
            return null;
        }
        $returned = in_array($latest->status, [AgreementStatus::HandedBack, AgreementStatus::Ended], true);

        return $latest->status->isActive() || $returned ? $latest : null;
    }

    /**
     * The active agreement's view, else the latest ended one's (the API and
     * Ask, spec.md §7.20 *Finance*); null without one or without access.
     */
    public function latestView(User $user, Vehicle $vehicle): ?AgreementView
    {
        if (!$this->canSee($user, $vehicle)) {
            return null;
        }
        $latest = $this->agreements->listForVehicle($vehicle->id)[0] ?? null;

        return $latest === null ? null : $this->view($user, $vehicle, $latest);
    }

    /**
     * Manual finance expenses that may count twice with the vehicle's
     * agreements (the Expenses tab's notice), without access none.
     *
     * @return list<\Logbook\Domain\Expense\ExpenseEntry>
     */
    public function overlapFor(User $user, Vehicle $vehicle): array
    {
        if (!$this->canSee($user, $vehicle)) {
            return [];
        }
        $agreements = $this->agreements->listForVehicle($vehicle->id);
        if ($agreements === []) {
            return [];
        }
        $expenses = $this->expenses->listForVehicle($vehicle->id);
        $events = $this->agreements->eventsForAgreements(array_map(static fn (FinanceAgreement $a): int => $a->id, $agreements));
        $today = $this->ownerToday($user, $vehicle);
        $overlap = [];
        foreach ($agreements as $agreement) {
            $schedule = Schedule::of($agreement, $events[$agreement->id] ?? [], $today);
            foreach (FinanceLedger::overlapping($agreement, $schedule, $expenses) as $entry) {
                $overlap[$entry->id] = $entry;
            }
        }

        return array_values($overlap);
    }

    /**
     * The derived cost lines of every agreement on these vehicles (spec.md
     * §7.32 *Costs*), whoever is looking: the ledger counts them for every
     * cost viewer (#125). Nothing with the module off.
     *
     * @param list<Vehicle> $vehicles
     * @return array<int, list<array{agreement: FinanceAgreement, line: FinanceLine}>> by vehicle id
     */
    public function costLines(User $viewer, array $vehicles): array
    {
        if (!$this->features->isEnabled(Feature::Finance) || $vehicles === []) {
            return [];
        }
        $byId = [];
        foreach ($vehicles as $vehicle) {
            $byId[$vehicle->id] = $vehicle;
        }
        $agreements = $this->agreements->listForVehicles(array_keys($byId));
        if ($agreements === []) {
            return [];
        }
        $ids = array_map(static fn (FinanceAgreement $a): int => $a->id, $agreements);
        $events = $this->agreements->eventsForAgreements($ids);

        $lines = [];
        foreach ($agreements as $agreement) {
            if (!$agreement->data->countInCosts) {
                continue;
            }
            $vehicle = $byId[$agreement->vehicleId];
            $today = $this->ownerToday($viewer, $vehicle);
            $schedule = Schedule::of($agreement, $events[$agreement->id] ?? [], $today);
            $currency = $this->vehicles->currencyFor($viewer, $vehicle);
            $figures = AgreementFigures::of($agreement, $schedule, [], null, $today, $currency);
            foreach (FinanceLedger::lines($agreement, $figures) as $line) {
                $lines[$vehicle->id][] = ['agreement' => $agreement, 'line' => $line];
            }
        }

        return $lines;
    }

    /**
     * @throws AgreementAlreadyActive
     */
    public function create(User $user, Vehicle $vehicle, FinanceInput $input): int
    {
        $this->assertCanSee($user, $vehicle);
        if ($this->agreements->activeFor($vehicle->id) !== null) {
            throw new AgreementAlreadyActive();
        }
        $id = $this->agreements->insert($vehicle->id, $input->data, $this->clock->now(), $user->id);
        $this->applyPurchasePrice($vehicle, $input);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryCreated, WebhookKind::Finance, $id);

        return $id;
    }

    public function update(User $user, Vehicle $vehicle, FinanceAgreement $agreement, FinanceInput $input): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->update($vehicle->id, $agreement->id, $input->data, $this->clock->now());
        $this->applyPurchasePrice($vehicle, $input);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);
    }

    public function delete(User $user, Vehicle $vehicle, FinanceAgreement $agreement): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->delete($vehicle->id, $agreement->id);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryDeleted, WebhookKind::Finance, $agreement->id);
    }

    /**
     * Mark a scheduled payment missed, or a missed one paid late.
     */
    public function markPayment(
        User $user,
        Vehicle $vehicle,
        FinanceAgreement $agreement,
        PaymentEventKind $kind,
        DateTimeImmutable $dueOn,
        ?DateTimeImmutable $paidOn,
    ): void {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->insertEvent($agreement->id, $kind, $dueOn, null, $paidOn, null, $this->clock->now());
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);
    }

    public function addExtraPayment(
        User $user,
        Vehicle $vehicle,
        FinanceAgreement $agreement,
        string $amount,
        DateTimeImmutable $paidOn,
        ?string $notes,
    ): void {
        $this->assertCanSee($user, $vehicle);
        $now = $this->clock->now();
        $this->agreements->insertEvent($agreement->id, PaymentEventKind::Extra, null, $amount, $paidOn, $notes, $now);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);
    }

    /**
     * Remove an event: a missed mark (and the paid-late mark that followed
     * it), or an extra payment.
     */
    public function deleteEvent(User $user, Vehicle $vehicle, FinanceAgreement $agreement, int $eventId): void
    {
        $this->assertCanSee($user, $vehicle);
        foreach ($this->agreements->eventsFor($agreement->id) as $event) {
            if ($event->id !== $eventId) {
                continue;
            }
            $this->agreements->deleteEvent($agreement->id, $event->id);
            if ($event->kind === PaymentEventKind::Missed && $event->dueOn !== null) {
                foreach ($this->agreements->eventsFor($agreement->id) as $other) {
                    if ($other->kind === PaymentEventKind::PaidLate && $other->dueOn == $event->dueOn) {
                        $this->agreements->deleteEvent($agreement->id, $other->id);
                    }
                }
            }
            $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);

            return;
        }

        throw new FinanceAgreementNotFound();
    }

    public function addQuote(
        User $user,
        Vehicle $vehicle,
        FinanceAgreement $agreement,
        DateTimeImmutable $quotedOn,
        string $amount,
        DateTimeImmutable $validUntil,
        ?string $notes,
    ): void {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->insertQuote($agreement->id, $quotedOn, $amount, $validUntil, $notes, $this->clock->now());
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);
    }

    public function deleteQuote(User $user, Vehicle $vehicle, FinanceAgreement $agreement, int $quoteId): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->deleteQuote($agreement->id, $quoteId);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);
    }

    /**
     * Whether the form should offer to set the purchase price (HP or PCP,
     * none set) or to clear it (a lease, one set).
     *
     * @return array{set: bool, clear: bool}
     */
    public function purchasePriceOffers(Vehicle $vehicle, AgreementType $type): array
    {
        $price = $vehicle->data->purchasePrice;

        return [
            'set' => $type->hasCashPrice() && $price === null,
            'clear' => $type === AgreementType::Lease && $price !== null,
        ];
    }

    private function applyPurchasePrice(Vehicle $vehicle, FinanceInput $input): void
    {
        $offers = $this->purchasePriceOffers($vehicle, $input->data->type);
        if ($input->setPurchasePrice && $offers['set'] && $input->data->cashPrice !== null) {
            $this->vehicleRows->setPurchasePrice($vehicle->userId, $vehicle->id, $input->data->cashPrice, $this->clock->now());
        }
        if ($input->clearPurchasePrice && $offers['clear']) {
            $this->vehicleRows->setPurchasePrice($vehicle->userId, $vehicle->id, null, $this->clock->now());
        }
    }

    private function latestValuation(Vehicle $vehicle, DateTimeImmutable $today): ?VehicleValuation
    {
        $latest = null;
        foreach ($this->valuations->listForVehicle($vehicle->id) as $valuation) {
            if ($valuation->data->valuedOn <= $today) {
                $latest = $valuation;
            }
        }

        return $latest;
    }

    /**
     * The ways an agreement of this type can end (spec.md §7.32 *Ending*):
     * HP, PCP and loans settle early or complete; a PCP can be handed back;
     * a lease ends.
     *
     * @return list<AgreementStatus>
     */
    public static function endOutcomes(AgreementType $type): array
    {
        return match ($type) {
            AgreementType::Hp, AgreementType::Loan => [AgreementStatus::Settled, AgreementStatus::Completed],
            AgreementType::Pcp => [AgreementStatus::Settled, AgreementStatus::Completed, AgreementStatus::HandedBack],
            AgreementType::Lease => [AgreementStatus::Ended],
        };
    }

    /**
     * What the *End agreement* form starts from: today, the settlement
     * figure (quote or estimate), the last payment's date for *Completed*,
     * and the excess mileage charge at the distance so far, when over.
     *
     * @return array{ended_on: string, completed_on: ?string, settlement: ?string, excess_charge: ?string}
     */
    public function endDefaults(User $user, Vehicle $vehicle, AgreementView $view): array
    {
        $today = $this->ownerToday($user, $vehicle);
        $mileage = $view->mileage;
        $excess = null;
        if ($mileage !== null && $mileage->distanceKm !== null && $mileage->chargePerUnit !== null) {
            $over = (float) $mileage->unit->fromKmDecimal($mileage->distanceKm, 3)
                - (float) $mileage->unit->fromKmDecimal($mileage->allowanceKm, 3);
            if ($over > 0) {
                $excess = FinanceMath::money(FinanceMath::of(sprintf('%.3F', $over))->multipliedBy($mileage->chargePerUnit));
            }
        }

        return [
            'ended_on' => $today->format('Y-m-d'),
            'completed_on' => $view->figures->endsOn?->format('Y-m-d'),
            'settlement' => $view->figures->settlement?->amount->toDecimal(2),
            'excess_charge' => $excess,
        ];
    }

    /**
     * End an active agreement (spec.md §7.32 *Ending*): a settlement payment
     * for *Settled early*, the status and end date, the charges logged on
     * handing back as *Finance and lease* expenses on the end date (#127),
     * and its finance reminders done.
     */
    public function end(User $user, Vehicle $vehicle, FinanceAgreement $agreement, EndAgreement $end): void
    {
        $this->assertCanSee($user, $vehicle);
        if (!$agreement->status->isActive() || !in_array($end->outcome, self::endOutcomes($agreement->type()), true)) {
            throw new FinanceAgreementNotFound();
        }
        $now = $this->clock->now();
        if ($end->outcome === AgreementStatus::Settled && $end->settlement !== null) {
            $this->agreements->insertEvent(
                $agreement->id,
                PaymentEventKind::Settlement,
                null,
                $end->settlement,
                $end->endedOn,
                null,
                $now,
            );
        }
        $this->agreements->setStatus($vehicle->id, $agreement->id, $end->outcome, $end->endedOn, $now);
        $this->webhooks->entry($vehicle, WebhookEvent::EntryUpdated, WebhookKind::Finance, $agreement->id);

        $charges = ['finance.end.excess_note' => $end->excessCharge, 'finance.end.damage_note' => $end->damageCharge];
        foreach ($charges as $note => $amount) {
            if ($amount === null) {
                continue;
            }
            $this->expenseEntries->create($vehicle, new ExpenseEntryData(
                $end->endedOn,
                ExpenseCategory::Finance,
                $amount,
                $this->translator->trans($note, ['lender' => $agreement->data->lender]),
            ));
        }

        $closing = array_filter(
            $this->reminders->listGeneratedForVehicles([$vehicle->id]),
            static fn (Reminder $r): bool => in_array($r->source, [ReminderSource::Finance, ReminderSource::FinanceEnd], true)
                && $r->sourceId === $agreement->id
                && $r->status->isOpen(),
        );
        foreach ([ReminderSource::Finance, ReminderSource::FinanceEnd] as $source) {
            $this->reminders->markDone($vehicle->id, $source, $agreement->id, $now);
        }
        foreach ($closing as $reminder) {
            $this->webhooks->reminder($vehicle->id, $reminder->id, 'done');
        }
    }

    /** Whether an agreement may still record payment events (active only). */
    public static function isOpen(FinanceAgreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::Active;
    }
}
