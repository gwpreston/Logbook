<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use Logbook\Domain\Expense\CostGroup;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartStatus;
use Logbook\Domain\Part\RemovalOutcome;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\ModificationRepository;
use Logbook\Repository\PartRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Cache\RequestReads;

/**
 * The unified cost ledger (spec.md §7.7): fill-ups, maintenance and documents
 * with a cost, ad-hoc expenses, and from Phase 29.1 the lines derived from
 * finance agreements (§7.32), read from their own tables on every call.
 * Nothing is copied into expense_entries, so a cost can neither go stale nor
 * be counted twice. Costs of a switched-off module are left out.
 */
final readonly class CostLedger
{
    /**
     * Every table the ledger is built from, those its reads join included
     * (a fill-up's title is its station's name), so a write to any of them
     * forgets it (RequestReads::forget()).
     */
    private const string READS = 'fuel_entries+stations+maintenance_entries+compliance_documents+expense_entries'
        . '+modifications+parts+part_fittings+finance_agreements+finance_payment_events+settlement_quotes+settings';

    public function __construct(
        private FuelEntryRepository $fuel,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private VehicleService $vehicles,
        private FeatureToggles $features,
        private FinanceService $finance,
        private ModificationRepository $modifications,
        private PartRepository $parts,
        private RequestReads $reads,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles the owner's vehicles to include
     * @return list<CostItem> oldest first (see CostItem::compare())
     */
    public function items(User $user, array $vehicles): array
    {
        // A page's widgets ask for the same ledger several times: built
        // once per request, and forgotten when any table it reads is
        // written (a write to vehicles or users forgets everything) (Phase 54.5).
        $key = implode('|', [
            $user->id,
            $user->preferences->timezone,
            $user->preferences->currency,
            implode(',', array_map(static fn (Vehicle $v): int => $v->id, $vehicles)),
        ]);

        return $this->reads->remember(self::READS, $key, fn (): array => $this->build($user, $vehicles));
    }

    /**
     * @param list<Vehicle> $vehicles
     * @return list<CostItem>
     */
    private function build(User $user, array $vehicles): array
    {
        $zone = $user->preferences->timeZone();
        $enabled = $this->features->all();
        $items = [];
        $finance = $this->finance->costLines($user, $vehicles);

        foreach ($vehicles as $vehicle) {
            $currency = $this->vehicles->currencyFor($user, $vehicle);

            if ($enabled[Feature::Fuel->value]) {
                foreach ($this->fuel->listForVehicle($vehicle->id) as $entry) {
                    $items[] = CostItem::fromFuel($entry, $vehicle, $currency, $zone);
                }
            }
            if ($enabled[Feature::Maintenance->value]) {
                foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
                    $items[] = CostItem::fromMaintenance($entry, $vehicle, $currency);
                }
            }
            if ($enabled[Feature::Compliance->value]) {
                foreach ($this->documents->listForVehicle($vehicle->id) as $document) {
                    $items[] = CostItem::fromCompliance($document, $vehicle, $currency, $zone);
                }
            }
            foreach ($this->expenses->listForVehicle($vehicle->id) as $entry) {
                $items[] = CostItem::fromExpense($entry, $vehicle, $currency);
            }
            if ($enabled[Feature::Parts->value]) {
                foreach ($this->buildLines($vehicle, $currency) as $item) {
                    $items[] = $item;
                }
            }
            // Phase 29.1: credit charges and rentals derived from finance agreements.
            foreach ($finance[$vehicle->id] ?? [] as ['agreement' => $agreement, 'line' => $line]) {
                $items[] = CostItem::fromFinance($vehicle, $agreement, $line, $currency);
            }
        }

        $items = array_values(array_filter($items, static fn (?CostItem $item): bool => $item !== null));

        return CostItem::sort($items);
    }

    /**
     * Phase 50.1 (spec.md §7.39): each modification's cost, and each part
     * sold as it came off as money back, in the group of the record that
     * first fitted it (*Maintenance* for a service record, else
     * *Modifications*, #420).
     *
     * @return list<CostItem|null>
     */
    private function buildLines(Vehicle $vehicle, string $currency): array
    {
        $items = [];
        foreach ($this->modifications->listForVehicle($vehicle->id) as $modification) {
            $items[] = CostItem::fromModification($modification, $vehicle, $currency);
        }
        $parts = $this->parts->listForVehicle($vehicle->id);
        $sold = array_values(array_filter($parts, static fn (Part $p): bool => $p->status === PartStatus::Gone));
        if ($sold === []) {
            return $items;
        }
        $fittings = $this->parts->fittingsForVehicle($vehicle->id);
        foreach ($sold as $part) {
            $list = $fittings[$part->id] ?? [];
            $last = $list === [] ? null : $list[count($list) - 1];
            if ($last === null || $last->outcome !== RemovalOutcome::Sold) {
                continue;
            }
            $group = $list[0]->fittedMaintenanceId !== null ? CostGroup::Maintenance : CostGroup::Build;
            $items[] = CostItem::fromPartSale($part, $last, $group, $vehicle, $currency);
        }

        return $items;
    }
}
