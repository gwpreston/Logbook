<?php

declare(strict_types=1);

namespace Logbook\Service\Expense;

use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Vehicle\VehicleService;

/**
 * The unified cost ledger (spec.md §7.7): fill-ups, maintenance and documents
 * with a cost, and ad-hoc expenses, read from their own tables on every call.
 * Nothing is copied into expense_entries, so a cost can neither go stale nor
 * be counted twice. Costs of a switched-off module are left out.
 */
final readonly class CostLedger
{
    public function __construct(
        private FuelEntryRepository $fuel,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private VehicleService $vehicles,
        private FeatureToggles $features,
    ) {
    }

    /**
     * @param list<Vehicle> $vehicles the owner's vehicles to include
     * @return list<CostItem> oldest first (see CostItem::compare())
     */
    public function items(User $user, array $vehicles): array
    {
        $zone = $user->preferences->timeZone();
        $enabled = $this->features->all();
        $items = [];

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
        }

        $items = array_values(array_filter($items, static fn (?CostItem $item): bool => $item !== null));
        usort($items, CostItem::compare(...));

        return $items;
    }
}
