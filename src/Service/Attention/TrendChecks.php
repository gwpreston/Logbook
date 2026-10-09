<?php

declare(strict_types=1);

namespace Logbook\Service\Attention;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attention\AttentionKind;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\FuelEntry;
use Logbook\Domain\Tyre\TyreChangeKind;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\TyreRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Fuel\FuelHistory;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Currency;

/**
 * The trend and cost checks of *Needs attention* (spec.md §7.24 items 7–9,
 * Phase 25) as items for one user and vehicle: economy drift per series,
 * fuel price outliers and maintenance cost outliers, judged by the owner's
 * thresholds and today. Plain arithmetic (EconomyDrift, PriceOutlier,
 * CostOutlier); this class only loads what they need, once per vehicle,
 * and decides who may see and hide each item: price and cost items show
 * amounts, so only to users who may see the vehicle's costs.
 */
final readonly class TrendChecks
{
    public function __construct(
        private MaintenanceEntryRepository $maintenance,
        private TyreRepository $tyres,
        private FuelEntryRepository $fuel,
        private VehicleRepository $vehicles,
        private AppSettings $settings,
    ) {
    }

    /**
     * @param array<string, bool> $enabled module toggles
     * @param FuelHistory|null $history the vehicle's fuel history (fuel on)
     * @param bool $serviceOverdue a service schedule is overdue (from *Coming up*)
     * @param bool $costs the user may see the vehicle's amounts (Phase 19): price and cost items show them
     * @param PriceBook $book the owners' fill-ups, shared across one list's vehicles
     * @return list<AttentionItem>
     */
    public function items(
        User $user,
        Vehicle $vehicle,
        User $owner,
        AttentionThresholds $thresholds,
        bool $manage,
        bool $costs,
        array $enabled,
        ?FuelHistory $history,
        bool $serviceOverdue,
        DateTimeImmutable $now,
        PriceBook $book,
    ): array {
        $zone = $owner->preferences->timeZone();
        $items = [];
        if ($history !== null && !$history->isEmpty()) {
            array_push($items, ...$this->drift($vehicle, $history, $thresholds, $enabled, $serviceOverdue, $now, $zone));
            if ($costs) {
                array_push($items, ...$this->prices($user, $vehicle, $owner, $history, $thresholds, $manage, $book));
            }
        }
        if ($costs && $enabled[Feature::Maintenance->value]) {
            $today = LocalTime::dateOf($now, $zone);
            array_push($items, ...$this->costs($user, $vehicle, $owner, $thresholds, $manage, $today));
        }

        return $items;
    }

    /**
     * *Economy up* (Phase 42, spec.md §7.8): the series whose drift test
     * came out better, judged exactly as item 7 (the owner's thresholds and
     * time zone), so the insight and the item never disagree.
     *
     * @param array<string, bool> $enabled module toggles
     * @return list<DriftFinding>
     */
    public function improvements(
        Vehicle $vehicle,
        FuelHistory $history,
        AttentionThresholds $thresholds,
        array $enabled,
        DateTimeImmutable $now,
        DateTimeZone $zone,
    ): array {
        if ($history->isEmpty()) {
            return [];
        }

        return array_values(array_filter(
            $this->judged($vehicle, $history, $thresholds, $enabled, false, $now, $zone),
            static fn (DriftFinding $finding): bool => $finding->improved,
        ));
    }

    /**
     * Item 7, one per series that drifted.
     *
     * @param array<string, bool> $enabled
     * @return list<AttentionItem>
     */
    private function drift(
        Vehicle $vehicle,
        FuelHistory $history,
        AttentionThresholds $thresholds,
        array $enabled,
        bool $serviceOverdue,
        DateTimeImmutable $now,
        DateTimeZone $zone,
    ): array {
        $items = [];
        foreach ($this->judged($vehicle, $history, $thresholds, $enabled, $serviceOverdue, $now, $zone) as $finding) {
            if ($finding->improved) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: match ($finding->kind) {
                    EnergyKind::Liquid => AttentionKind::DriftLiquid,
                    EnergyKind::Electric => AttentionKind::DriftElectric,
                    EnergyKind::Gas => AttentionKind::DriftGas,
                },
                vehicle: $vehicle,
                subjectId: $vehicle->id,
                icon: 'trending_up',
                drift: $finding,
                fingerprint: Fingerprint::drift($finding),
                canAct: true,
                canHide: true,
            );
        }

        return $items;
    }

    /**
     * The drift test of every series, both outcomes (EconomyDrift::judge).
     *
     * @param array<string, bool> $enabled
     * @return list<DriftFinding>
     */
    private function judged(
        Vehicle $vehicle,
        FuelHistory $history,
        AttentionThresholds $thresholds,
        array $enabled,
        bool $serviceOverdue,
        DateTimeImmutable $now,
        DateTimeZone $zone,
    ): array {
        $fits = null;
        if ($enabled[Feature::Tyres->value]) {
            $fits = function () use ($vehicle): array {
                $dates = [];
                foreach ($this->tyres->listChanges($vehicle->id) as $change) {
                    if ($change->kind === TyreChangeKind::Fit) {
                        // Already a calendar date (midnight UTC), not an instant.
                        $dates[] = $change->data->doneOn;
                    }
                }

                return $dates;
            };
        }

        $findings = [];
        foreach (EnergyKind::cases() as $kind) {
            if ($history->summary($kind) === null) {
                continue;
            }
            $finding = EconomyDrift::judge(
                $history,
                $kind,
                $kind === EnergyKind::Electric ? $thresholds->driftPercentElectric : $thresholds->driftPercent,
                $now,
                $zone,
                $fits,
                $serviceOverdue,
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Item 8: Manage, or Log for a fill-up the user added.
     *
     * @return list<AttentionItem>
     */
    private function prices(
        User $user,
        Vehicle $vehicle,
        User $owner,
        FuelHistory $history,
        AttentionThresholds $thresholds,
        bool $manage,
        PriceBook $book,
    ): array {
        $entries = array_map(static fn ($fill): FuelEntry => $fill->entry, $history->fills);
        $currency = $this->currency($vehicle, $owner);
        $findings = PriceOutlier::of(
            $entries,
            fn (): array => $book->fillUps($owner->id, $currency, fn (): array => $this->ownerFillUps($owner, $currency)),
            $thresholds->pricePercent,
        );

        $items = [];
        foreach ($findings as $finding) {
            if (!$manage && !EntryAccess::isOwn($user, $finding->entry->createdBy)) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: AttentionKind::FuelPrice,
                vehicle: $vehicle,
                subjectId: $finding->entry->id,
                icon: 'local_gas_station',
                price: $finding,
                currency: $currency,
                fingerprint: Fingerprint::price($finding->entry),
                canAct: true,
                canHide: true,
            );
        }

        return $items;
    }

    /**
     * Item 9: Manage, or Log for a record the user added.
     *
     * @return list<AttentionItem>
     */
    private function costs(
        User $user,
        Vehicle $vehicle,
        User $owner,
        AttentionThresholds $thresholds,
        bool $manage,
        DateTimeImmutable $today,
    ): array {
        $findings = CostOutlier::of(
            $this->maintenance->listForVehicle($vehicle->id),
            LocalTime::addMonths($today, -12),
            $thresholds->costMultiple,
            $thresholds->costFloor,
        );
        if ($findings === []) {
            return [];
        }
        $currency = $this->currency($vehicle, $owner);
        $items = [];
        foreach ($findings as $finding) {
            if (!$manage && !EntryAccess::isOwn($user, $finding->entry->createdBy)) {
                continue;
            }
            $items[] = new AttentionItem(
                kind: AttentionKind::MaintenanceCost,
                vehicle: $vehicle,
                subjectId: $finding->entry->id,
                icon: $finding->entry->data->category->icon(),
                cost: $finding,
                currency: $currency,
                fingerprint: Fingerprint::cost($finding->entry),
                canAct: true,
                canHide: true,
            );
        }
        return $items;
    }

    /**
     * The owner's fill-ups on every vehicle of theirs in this currency.
     *
     * @return list<FuelEntry>
     */
    private function ownerFillUps(User $owner, string $currency): array
    {
        $ids = [];
        foreach ($this->vehicles->listByIds($this->vehicles->idsOwnedBy($owner->id, null)) as $vehicle) {
            if ($this->currency($vehicle, $owner) === $currency) {
                $ids[] = $vehicle->id;
            }
        }

        return $ids === [] ? [] : $this->fuel->listForVehiclesBetween($ids, null, null);
    }

    public function currency(Vehicle $vehicle, User $owner): string
    {
        return Currency::resolve($vehicle->data->currency, $owner->preferences->currency, $this->settings->currency);
    }
}
