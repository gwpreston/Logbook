<?php

declare(strict_types=1);

namespace Logbook\Service\Finance;

use DateTimeImmutable;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\FinanceAgreement;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuation;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FinanceAgreementRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Psr\Clock\ClockInterface;

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
        private UserDirectory $directory,
        private VehicleAccess $access,
        private FeatureToggles $features,
        private ClockInterface $clock,
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
        $owner = $vehicle->userId === $viewer->id ? $viewer : ($this->directory->find($vehicle->userId) ?? $viewer);

        return LocalTime::today($this->clock, $owner->preferences->timeZone());
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

        return new AgreementView(
            agreement: $agreement,
            figures: $figures,
            events: $events,
            quotes: $quotes,
            checks: FinanceCheck::of($agreement->data),
            overlap: FinanceLedger::overlapping($agreement, $schedule, $this->expenses->listForVehicle($vehicle->id)),
            currency: $currency,
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

        return $id;
    }

    public function update(User $user, Vehicle $vehicle, FinanceAgreement $agreement, FinanceInput $input): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->update($vehicle->id, $agreement->id, $input->data, $this->clock->now());
        $this->applyPurchasePrice($vehicle, $input);
    }

    public function delete(User $user, Vehicle $vehicle, FinanceAgreement $agreement): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->delete($vehicle->id, $agreement->id);
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
    }

    public function deleteQuote(User $user, Vehicle $vehicle, FinanceAgreement $agreement, int $quoteId): void
    {
        $this->assertCanSee($user, $vehicle);
        $this->agreements->deleteQuote($agreement->id, $quoteId);
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

    /** Whether an agreement may still record payment events (active only). */
    public static function isOpen(FinanceAgreement $agreement): bool
    {
        return $agreement->status === AgreementStatus::Active;
    }
}
