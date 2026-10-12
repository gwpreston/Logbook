<?php

declare(strict_types=1);

namespace Logbook\Tests\Support\Golden;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Finance\AgreementData;
use Logbook\Domain\Finance\AgreementStatus;
use Logbook\Domain\Finance\AgreementType;
use Logbook\Domain\Finance\PaymentEventKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Incident\Claim;
use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\Fault;
use Logbook\Domain\Incident\IncidentData;
use Logbook\Domain\Incident\IncidentStatus;
use Logbook\Domain\Incident\IncidentType;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Trip\MileageRateSetData;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Valuation\VehicleValuationData;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Auth\AuthService;
use Logbook\Service\Auth\SetupData;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Finance\EndAgreement;
use Logbook\Service\Finance\FinanceInput;
use Logbook\Service\Finance\FinanceService;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Sharing\SharingService;
use Logbook\Service\Trip\MileageRateService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Trip\TripSettings;
use Logbook\Service\Trip\TripSettingsStore;
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
use Slim\Psr7\UploadedFile;

/**
 * Enters the golden household (tests/Fixtures/golden/inputs/*.csv) through
 * the services, as the app's own pages would, never through SQL, so every
 * derived row (a fill-up's odometer reading, a finance event, a reminder) is
 * made the way the app makes it. The container's clock should already be
 * pinned to InputBuilder::TODAY_UTC.
 *
 * A few entries carry a receipt (attachments.csv, files in ../uploads/),
 * attached as the entry is saved, as the forms do.
 *
 * Entries are written through the services with canonical values (km,
 * litres, kWh); the forms' own checks (decimal places, ranges) are not
 * exercised here.
 */
final class GoldenHousehold
{
    public const string INPUTS = __DIR__ . '/../../Fixtures/golden/inputs';

    /** @var array<string, User> */
    public array $users = [];
    /** @var array<string, Vehicle> */
    public array $vehicles = [];
    /** @var array<string, string> `kind|vehicle|at` → the receipt's path, until it is attached */
    private array $receipts = [];

    private function __construct(private ContainerInterface $container)
    {
    }

    public static function load(ContainerInterface $container, string $password, string $inputs = self::INPUTS): self
    {
        $household = new self($container);
        $household->enter($inputs, $password);

        return $household;
    }

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new RuntimeException("No user {$key}");
    }

    public function vehicle(string $key): Vehicle
    {
        return $this->vehicles[$key] ?? throw new RuntimeException("No vehicle {$key}");
    }

    private function enter(string $inputs, string $password): void
    {
        $csv = static fn (string $name): array => ExpectedSkeleton::read($inputs . '/' . $name . '.csv');

        foreach ($csv('attachments') as $row) {
            $key = $row['kind'] . '|' . $row['vehicle'] . '|' . $row['at'];
            $this->receipts[$key] = dirname($inputs) . '/uploads/' . $row['file'];
        }
        $this->people($csv('users'), $password);
        foreach ($csv('vehicles') as $row) {
            $this->addVehicle($row);
        }
        foreach ($csv('shares') as $row) {
            $refusal = $this->get(SharingService::class)->add(
                $this->vehicle($row['vehicle']),
                $row['user'],
                ShareLevel::from($row['level']),
                $row['costs'] === 'yes',
                $row['notify'] === 'yes',
            );
            if ($refusal !== null) {
                throw new RuntimeException("Share of {$row['vehicle']} with {$row['user']} refused");
            }
        }
        foreach ($csv('readings') as $row) {
            $this->as($row['by']);
            $this->get(OdometerService::class)->create(
                $this->vehicle($row['vehicle']),
                new OdometerReadingData($row['odometer_km'], $this->instant($row['at'], $row['by']), $row['note']),
            );
        }
        foreach ($csv('fillups') as $row) {
            $this->as($row['by']);
            $this->get(FuelService::class)->create($this->vehicle($row['vehicle']), new FuelEntryData(
                $this->instant($row['at'], $row['by']),
                $row['odometer_km'],
                Fuel::from($row['fuel']),
                $row['volume'],
                $row['price_per_unit'],
                $row['total_cost'],
                $row['partial'] === 'yes',
                station: $row['station'],
            ), $this->receipt('fillups', $row['vehicle'], $row['at']));
        }
        $this->ownerEntries($csv);
        $this->finance($csv('finance'), $csv('finance_events'));
        $this->trips($csv('rate_sets'), $csv('trips'));
        $this->sales($csv('vehicles'));
        $this->as(null);
        if ($this->receipts !== []) {
            throw new RuntimeException('No entry for the receipts ' . implode(', ', array_keys($this->receipts)));
        }
    }

    /**
     * The receipt attached to an entry as it is saved, if attachments.csv
     * lists one: a copy, since the upload check cleans an image in place.
     */
    private function receipt(string $kind, string $vehicle, string $at): PendingUploads
    {
        $key = $kind . '|' . $vehicle . '|' . $at;
        $source = $this->receipts[$key] ?? null;
        if ($source === null) {
            return new PendingUploads();
        }
        unset($this->receipts[$key]);
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-golden-');
        if (!copy($source, $path)) {
            throw new RuntimeException("Cannot copy {$source}");
        }
        $file = new UploadedFile($path, basename($source), null, (int) filesize($path), UPLOAD_ERR_OK);
        $check = $this->get(AttachmentService::class)->check($file);
        if (!$check->isValid()) {
            throw new RuntimeException("The receipt {$source} was refused: {$check->error}");
        }

        return new PendingUploads([new PendingUpload($file, $check)]);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function people(array $rows, string $password): void
    {
        foreach ($rows as $row) {
            $uk = $row['distance_unit'] === 'mi';
            $preferences = new DisplayPreferences(
                $row['locale'],
                $row['timezone'],
                DistanceUnit::from($row['distance_unit']),
                VolumeUnit::from($row['volume_unit']),
                $uk ? ConsumptionUnit::MpgUk : ConsumptionUnit::LitresPer100Km,
                $row['currency'],
            );
            $user = $row['is_admin'] === 'yes'
                ? $this->get(AuthService::class)->createInitialUser(
                    new SetupData($row['user'], $password, $row['display_name'], $preferences),
                )
                : $this->get(UserAdmin::class)->addUser(
                    $this->firstAdmin(),
                    $row['user'],
                    $row['display_name'],
                    $row['user'] . '@example.test',
                    false,
                    $preferences,
                );
            $this->get(TripSettingsStore::class)->save($user, new TripSettings($row['tax_year_start']));
            $this->users[$row['user']] = $user;
        }
    }

    private function firstAdmin(): User
    {
        foreach ($this->users as $user) {
            if ($user->isAdmin) {
                return $user;
            }
        }

        throw new RuntimeException('The first user in users.csv must be the admin');
    }

    /**
     * @param array<string, string> $row
     */
    private function addVehicle(array $row): void
    {
        $owner = $this->user($row['owner']);
        $this->as($row['owner']);
        $this->vehicles[$row['vehicle']] = $this->get(VehicleService::class)->create($owner, new VehicleData(
            VehicleType::from($row['type']),
            $row['make'],
            $row['model'],
            FuelType::from($row['fuel_type']),
            registration: $row['registration'],
            currency: $row['currency'],
            purchaseDate: self::date($row['purchase_date']),
            purchasePrice: $row['purchase_price'],
            variant: $row['variant'],
            firstRegisteredOn: self::date($row['first_registered_on']),
        ));
    }

    /**
     * Maintenance, documents, expenses, valuations, schedules and incidents:
     * entered by each vehicle's owner, in the owner's zone.
     *
     * @param callable(string): list<array<string, string>> $csv
     */
    private function ownerEntries(callable $csv): void
    {
        foreach ($csv('maintenance') as $row) {
            [$vehicle, $zone] = $this->owned($row['vehicle']);
            $this->get(MaintenanceService::class)->create($vehicle, new MaintenanceEntryData(
                self::day($row['date']),
                MaintenanceCategory::from($row['category']),
                $row['title'],
                $row['cost'],
                $row['odometer_km'],
                $row['vendor'],
            ), $zone, $this->receipt('maintenance', $row['vehicle'], $row['date']));
        }
        foreach ($csv('documents') as $row) {
            [$vehicle, $zone] = $this->owned($row['vehicle']);
            $this->get(ComplianceService::class)->create($vehicle, new ComplianceDocumentData(
                ComplianceType::from($row['type']),
                $row['title'],
                $row['provider'],
                null,
                self::date($row['start_on']),
                self::date($row['expiry_on']),
                $row['cost'],
            ), $zone, $this->receipt('documents', $row['vehicle'], $row['start_on']));
        }
        foreach ($csv('expenses') as $row) {
            [$vehicle] = $this->owned($row['vehicle']);
            $this->get(ExpenseService::class)->create($vehicle, new ExpenseEntryData(
                self::day($row['date']),
                ExpenseCategory::from($row['category']),
                $row['amount'],
                $row['note'],
            ), $this->receipt('expenses', $row['vehicle'], $row['date']));
        }
        foreach ($csv('valuations') as $row) {
            [$vehicle] = $this->owned($row['vehicle']);
            $this->get(ValuationService::class)->create(
                $vehicle,
                new VehicleValuationData(self::day($row['valued_on']), $row['amount'], $row['source']),
            );
        }
        foreach ($csv('schedules') as $row) {
            [$vehicle] = $this->owned($row['vehicle']);
            $this->get(ScheduleService::class)->create($vehicle, new MaintenanceScheduleData(
                MaintenanceCategory::from($row['category']),
                $row['title'],
                $row['interval_km'] === '' ? null : $row['interval_km'],
                $row['interval_months'] === '' ? null : (int) $row['interval_months'],
                self::date($row['baseline_done_on']),
                $row['baseline_done_km'],
            ));
        }
        foreach ($csv('incidents') as $row) {
            [$vehicle, $zone] = $this->owned($row['vehicle']);
            $this->get(IncidentService::class)->create($vehicle, new IncidentData(
                self::day($row['occurred_on']),
                IncidentType::from($row['type']),
                fault: Fault::from($row['fault']),
                description: $row['note'],
                status: IncidentStatus::from($row['status']),
                closedOn: self::date($row['closed_on']),
                claim: new Claim(
                    ClaimStatus::from($row['claim_status']),
                    $row['insurer'],
                    excess: $row['excess'],
                    payout: $row['payout'],
                    updatedOn: self::date($row['updated_on']),
                ),
            ), null, $zone);
        }
    }

    /**
     * @param list<array<string, string>> $agreements
     * @param list<array<string, string>> $events
     */
    private function finance(array $agreements, array $events): void
    {
        $finance = $this->get(FinanceService::class);
        $ids = [];
        foreach ($agreements as $row) {
            [$vehicle] = $this->owned($row['vehicle']);
            $owner = $this->user($this->ownerOf($row['vehicle']));
            $ids[$row['agreement']] = $finance->create($owner, $vehicle, new FinanceInput(new AgreementData(
                AgreementType::from($row['type']),
                $row['lender'],
                null,
                self::day($row['started_on']),
                self::day($row['first_payment_on']),
                (int) $row['number_of_payments'],
                $row['regular_payment'],
                self::orNull($row['first_payment']),
                self::orNull($row['final_payment']),
                self::date($row['final_payment_on']),
                self::orNull($row['cash_price']),
                $row['customer_deposit'],
                $row['dealer_contribution'],
                amountOfCredit: self::orNull($row['amount_of_credit']),
                apr: $row['apr'],
                documentationFee: self::orNull($row['documentation_fee']),
                optionToPurchaseFee: self::orNull($row['option_to_purchase_fee']),
                annualMileageAllowance: $row['annual_mileage_allowance'] === '' ? null : (int) $row['annual_mileage_allowance'],
                mileageUnit: DistanceUnit::from($row['mileage_unit']),
                excessMileageCharge: self::orNull($row['excess_mileage_charge']),
                startOdometerKm: self::orNull($row['start_odometer_km']),
                countInCosts: $row['count_in_costs'] === 'yes',
            )));
        }
        foreach ($events as $row) {
            [$vehicle] = $this->owned($row['vehicle']);
            $owner = $this->user($this->ownerOf($row['vehicle']));
            $agreement = $finance->get($owner, $vehicle, $ids[$row['agreement']]);
            match ($row['kind']) {
                'missed' => $finance->markPayment(
                    $owner,
                    $vehicle,
                    $agreement,
                    PaymentEventKind::Missed,
                    self::day($row['due_on']),
                    null,
                ),
                'paid_late' => $finance->markPayment(
                    $owner,
                    $vehicle,
                    $agreement,
                    PaymentEventKind::PaidLate,
                    self::day($row['due_on']),
                    self::day($row['date']),
                ),
                'settled' => $finance->end(
                    $owner,
                    $vehicle,
                    $agreement,
                    new EndAgreement(AgreementStatus::Settled, self::day($row['date']), $row['amount']),
                ),
                default => throw new RuntimeException("Unknown finance event {$row['kind']}"),
            };
        }
    }

    /**
     * @param list<array<string, string>> $rateSets
     * @param list<array<string, string>> $trips
     */
    private function trips(array $rateSets, array $trips): void
    {
        foreach ($rateSets as $row) {
            $this->get(MileageRateService::class)->create($this->user($row['user']), new MileageRateSetData(
                self::day($row['effective_from']),
                DistanceUnit::from($row['distance_unit']),
                $row['currency'],
                $row['car_rate'],
                self::orNull($row['car_threshold']),
                self::orNull($row['car_rate_after']),
                source: $row['source'],
            ));
        }
        foreach ($trips as $row) {
            $this->as($row['user']);
            $this->get(TripService::class)->create($this->vehicle($row['vehicle']), new TripData(
                self::day($row['date']),
                $row['from'],
                $row['to'],
                $row['return'] === 'yes',
                $row['distance_km'],
                isBusiness: $row['business'] === 'yes',
                purpose: $row['purpose'],
            ));
        }
    }

    /**
     * A sold vehicle: its sale date and price on the vehicle, then archived,
     * as the owner does it when nothing is owed on it.
     *
     * @param list<array<string, string>> $rows
     */
    private function sales(array $rows): void
    {
        $vehicles = $this->get(VehicleService::class);
        foreach ($rows as $row) {
            if ($row['sale_date'] === '') {
                continue;
            }
            [$vehicle] = $this->owned($row['vehicle']);
            $owner = $this->user($row['owner']);
            $data = $vehicle->data;
            $sold = new VehicleData(
                $data->type,
                $data->make,
                $data->model,
                $data->fuelType,
                $data->nickname,
                $data->year,
                $data->registration,
                $data->vin,
                $data->capacity,
                $data->currency,
                $data->purchaseDate,
                $data->purchasePrice,
                self::day($row['sale_date']),
                $row['sale_price'],
                $data->defaultGrade,
                $data->variant,
                $data->firstRegisteredOn,
                $data->firstInspectionDueOn,
                $data->purchaseSeller,
            );
            $vehicles->update($owner, $vehicle, $sold);
            $vehicles->archive($owner, $vehicles->get($owner, $vehicle->id));
            $this->vehicles[$row['vehicle']] = $vehicles->get($owner, $vehicle->id);
        }
    }

    /**
     * @return array{Vehicle, DateTimeZone}
     */
    private function owned(string $key): array
    {
        $owner = $this->ownerOf($key);
        $this->as($owner);

        return [$this->vehicle($key), InputBuilder::zoneOf($owner)];
    }

    private function ownerOf(string $key): string
    {
        $userId = $this->vehicle($key)->userId;
        foreach ($this->users as $name => $user) {
            if ($user->id === $userId) {
                return $name;
            }
        }

        throw new RuntimeException("No owner for {$key}");
    }

    /** Act as this user, so what is entered is theirs (the author of a fill-up or trip). */
    private function as(?string $user): void
    {
        $this->get(AccessContext::class)->apply($user === null ? null : $this->user($user));
    }

    private function instant(string $local, string $user): DateTimeImmutable
    {
        return (new DateTimeImmutable($local, InputBuilder::zoneOf($user)))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function day(string $date): DateTimeImmutable
    {
        return self::date($date) ?? throw new RuntimeException('A date is required');
    }

    private static function date(string $date): ?DateTimeImmutable
    {
        if ($date === '') {
            return null;
        }
        return LocalTime::parseDate($date) ?? throw new RuntimeException("Not a date: {$date}");
    }

    private static function orNull(string $value): ?string
    {
        return $value === '' ? null : $value;
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
