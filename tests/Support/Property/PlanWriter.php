<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Property;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupData;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\User\UserAdmin;
use Logbook\Service\Valuation\ValuationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Enters a generated household through the services (never SQL): the owner
 * and the member, the shares, each vehicle with its purchase-day reading,
 * every entry, then any sale. Entries can be entered in another order, and
 * one can be removed and entered again.
 */
final class PlanWriter
{
    public User $owner;
    public User $member;
    /** @var array<string, Vehicle> */
    public array $vehicles = [];
    /** @var array<string, list<array{Entry, int}>> each vehicle's entries with the id each was stored under */
    public array $stored = [];

    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @param (callable(list<Entry>): list<Entry>)|null $order rearranges each vehicle's entries before they go in
     */
    public function write(Plan $plan, string $password, ?callable $order = null): self
    {
        $this->owner = $this->get(AuthService::class)->createInitialUser(
            new SetupData('owner', $password, 'Owner', self::preferences($plan->ownerZone, true)),
        );
        $this->member = $this->get(UserAdmin::class)->addUser(
            $this->owner,
            'member',
            'Member',
            'member@example.test',
            false,
            self::preferences($plan->memberZone, false),
        );
        foreach ($plan->vehicles as $vehiclePlan) {
            $vehicle = $this->get(VehicleService::class)->create($this->owner, new VehicleData(
                VehicleType::Car,
                'Make',
                $vehiclePlan->key,
                FuelType::Petrol,
                currency: $vehiclePlan->currency,
                purchaseDate: self::day($vehiclePlan->purchaseDate),
                purchasePrice: $vehiclePlan->purchasePrice,
            ));
            $this->vehicles[$vehiclePlan->key] = $vehicle;
            $this->stored[$vehiclePlan->key] = [];
            $this->get(SharingService::class)->add(
                $vehicle,
                'member',
                $vehiclePlan->memberSeesCosts ? ShareLevel::View : ShareLevel::Log,
                $vehiclePlan->memberSeesCosts,
                true,
            );
            $this->get(OdometerService::class)->create($vehicle, new OdometerReadingData(
                $vehiclePlan->startKm,
                LocalTime::toUtc($vehiclePlan->purchaseDate . 'T12:00', new DateTimeZone($plan->ownerZone))
                    ?? throw new RuntimeException('No purchase instant'),
            ));
            $entries = $order === null ? $vehiclePlan->entries : $order($vehiclePlan->entries);
            foreach ($entries as $entry) {
                $this->add($vehiclePlan->key, $entry, new DateTimeZone($plan->ownerZone));
            }
        }
        foreach ($plan->vehicles as $vehiclePlan) {
            if ($vehiclePlan->saleDate !== null) {
                $this->sell($vehiclePlan);
            }
        }

        return $this;
    }

    /** Enter one entry, remembering where it was stored. */
    public function add(string $key, Entry $entry, DateTimeZone $zone): void
    {
        $vehicle = $this->vehicles[$key];
        $id = match ($entry->kind) {
            'fill' => $this->get(FuelService::class)->create($vehicle, new FuelEntryData(
                new DateTimeImmutable((string) $entry->at),
                (string) $entry->km,
                Fuel::Petrol,
                (string) $entry->volume,
                (string) $entry->price,
                $entry->amount,
                $entry->partial,
            ))->id,
            'expense' => $this->get(ExpenseService::class)->create($vehicle, new ExpenseEntryData(
                self::day((string) $entry->date),
                ExpenseCategory::from((string) $entry->category),
                $entry->amount,
            ))->id,
            'maintenance' => $this->get(MaintenanceService::class)->create($vehicle, new MaintenanceEntryData(
                self::day((string) $entry->date),
                MaintenanceCategory::Repair,
                'Repair',
                $entry->amount,
            ), $zone)->id,
            'document' => $this->get(ComplianceService::class)->create($vehicle, new ComplianceDocumentData(
                ComplianceType::Insurance,
                'Insurance',
                startOn: self::day((string) $entry->date),
                expiryOn: self::day((string) $entry->expiry),
                cost: $entry->amount,
            ), $zone)->id,
            'valuation' => $this->get(ValuationService::class)->create(
                $vehicle,
                new VehicleValuationData(self::day((string) $entry->date), $entry->amount),
            )->id,
            'payout' => $this->get(IncidentService::class)->create($vehicle, new IncidentData(
                self::day((string) $entry->date),
                IncidentType::Collision,
                status: IncidentStatus::Closed,
                closedOn: self::day((string) $entry->date),
                claim: new Claim(ClaimStatus::Settled, 'Insurer', excess: $entry->excess, payout: $entry->amount),
            ), null, $zone)->id,
            default => throw new RuntimeException("Unknown entry kind {$entry->kind}"),
        };
        $this->stored[$key][] = [$entry, $id];
    }

    /** Delete a stored entry through its service. */
    public function remove(string $key, int $index, DateTimeZone $zone): Entry
    {
        $vehicle = $this->vehicles[$key];
        [$entry, $id] = $this->stored[$key][$index];
        match ($entry->kind) {
            'fill' => $this->get(FuelService::class)->delete($vehicle, $this->get(FuelService::class)->get($vehicle, $id)),
            'expense' => $this->get(ExpenseService::class)->delete(
                $vehicle,
                $this->get(ExpenseService::class)->get($vehicle, $id),
            ),
            'maintenance' => $this->get(MaintenanceService::class)->delete(
                $vehicle,
                $this->get(MaintenanceService::class)->get($vehicle, $id),
                $zone,
            ),
            'document' => $this->get(ComplianceService::class)->delete(
                $vehicle,
                $this->get(ComplianceService::class)->get($vehicle, $id),
            ),
            'valuation' => $this->get(ValuationService::class)->delete(
                $vehicle,
                $this->get(ValuationService::class)->get($vehicle, $id),
            ),
            'payout' => $this->get(IncidentService::class)->delete(
                $vehicle,
                $this->get(IncidentService::class)->get($vehicle, $id),
            ),
            default => throw new RuntimeException("Unknown entry kind {$entry->kind}"),
        };
        array_splice($this->stored[$key], $index, 1);

        return $entry;
    }

    /**
     * The vehicles as the owner sees them now (after any sale).
     *
     * @return list<Vehicle>
     */
    public function fleet(): array
    {
        return $this->get(VehicleService::class)->listFleet($this->owner, true);
    }

    private function sell(VehiclePlan $plan): void
    {
        $service = $this->get(VehicleService::class);
        $vehicle = $service->get($this->owner, $this->vehicles[$plan->key]->id);
        $data = $vehicle->data;
        $service->update($this->owner, $vehicle, new VehicleData(
            $data->type,
            $data->make,
            $data->model,
            $data->fuelType,
            currency: $data->currency,
            purchaseDate: $data->purchaseDate,
            purchasePrice: $data->purchasePrice,
            saleDate: self::day((string) $plan->saleDate),
            salePrice: $plan->salePrice,
        ));
        $service->archive($this->owner, $service->get($this->owner, $vehicle->id));
        $this->vehicles[$plan->key] = $service->get($this->owner, $vehicle->id);
    }

    private static function preferences(string $zone, bool $uk): DisplayPreferences
    {
        return new DisplayPreferences(
            $uk ? 'en_GB' : 'en_IE',
            $zone,
            $uk ? DistanceUnit::Mile : DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            $uk ? ConsumptionUnit::MpgUk : ConsumptionUnit::LitresPer100Km,
            $uk ? 'GBP' : 'EUR',
        );
    }

    private static function day(string $date): DateTimeImmutable
    {
        return LocalTime::parseDate($date) ?? throw new RuntimeException("Not a date: {$date}");
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException("{$id} is not in the container");
        }

        return $service;
    }
}
