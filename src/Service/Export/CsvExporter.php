<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

use DateTimeImmutable;
use DateTimeInterface;
use Logbook\Domain\Tyre\TyreChangeLine;
use Logbook\Domain\Tyre\TyrePosition;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\ValuationRepository;
use Logbook\Domain\Expense\CostGroup;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Incident\ClaimsHistoryReport;
use Logbook\Repository\IncidentRepository;
use Logbook\Service\User\UserDirectory;
use Logbook\Service\Forecast\Forecast;
use Logbook\Service\Forecast\ForecastItem;
use Logbook\Service\Forecast\ForecastWording;
use Logbook\Service\Report\GroupTotal;
use Logbook\Service\Report\OwnershipCost;
use Logbook\Service\Report\OwnershipReport;
use Logbook\Service\Report\Report;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Repository\TripRepository;
use Logbook\Service\Trip\ClaimLine;
use Logbook\Service\Trip\ClaimReport;
use Logbook\Support\Csv\CsvNumber;
use Logbook\Support\Csv\CsvTable;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Money\Money;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Units\DepthUnit;
use Logbook\Support\Units\DistanceUnit;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV exports (spec.md §7.7): one table per vehicle and module, the
 * ledger lines of a report, and the ownership report's rows. Headers are in the owner's language with the
 * unit in brackets; numbers are plain decimals in the owner's units (see
 * CsvNumber); dates are ISO; instants are local wall-clock times.
 */
final readonly class CsvExporter
{
    public function __construct(
        private FuelEntryRepository $fuel,
        private OdometerReadingRepository $odometer,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenses,
        private VehicleService $vehicles,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private TyreService $tyres,
        private ValuationRepository $valuations,
        private ForecastWording $wording,
        private TripRepository $trips,
        private IncidentRepository $incidents,
        private UserDirectory $directory,
    ) {
    }

    public function module(User $user, Vehicle $vehicle, ExportModule $module): CsvTable
    {
        [$header, $rows] = match ($module) {
            ExportModule::Fuel => $this->fuelTable($user, $vehicle),
            ExportModule::Odometer => $this->odometerTable($user, $vehicle),
            ExportModule::Maintenance => $this->maintenanceTable($user, $vehicle),
            ExportModule::Documents => $this->documentsTable($user, $vehicle),
            ExportModule::Expenses => $this->expensesTable($user, $vehicle),
            ExportModule::Tyres => $this->tyresTable($user, $vehicle),
            ExportModule::TyreChanges => $this->tyreChangesTable($user, $vehicle),
            ExportModule::Valuations => $this->valuationsTable($user, $vehicle),
            ExportModule::Trips => $this->tripsTable($user, $vehicle),
            ExportModule::Incidents => $this->incidentsTable($user, $vehicle),
        };

        return new CsvTable(
            sprintf(
                'logbook-%s-%s-%s.csv',
                CsvTable::slug($vehicle->name(), 'vehicle-' . $vehicle->id),
                $module->value,
                LocalTime::today($this->clock, $user->preferences->timeZone())->format('Y-m-d'),
            ),
            $header,
            $rows,
        );
    }

    /**
     * Every ledger line of a report, oldest first.
     */
    public function report(Report $report): CsvTable
    {
        $rows = array_map(fn (CostItem $item): array => [
            $item->date->format('Y-m-d'),
            $item->vehicle->name(),
            $item->vehicle->data->registration,
            $this->t('expense.group.' . $item->group()->value),
            $this->t($item->kindKey),
            $item->title,
            CsvNumber::money($item->amount->toDecimal(3), $item->currency()),
            $item->currency(),
        ], $report->items);

        return new CsvTable(
            sprintf(
                'logbook-report-%s-%s.csv',
                ($report->period->from ?? $report->period->to)->format('Y-m-d'),
                $report->period->to->format('Y-m-d'),
            ),
            $this->headers([
                'export.column.date',
                'export.column.vehicle',
                'export.column.registration',
                'export.column.group',
                'export.column.kind',
                'export.column.description',
                'export.column.amount',
                'export.column.currency',
            ]),
            $rows,
        );
    }

    /**
     * *Coming up* (spec.md §7.18): one row per item (overdue first, then by
     * date, then undated with a blank date), then one row per vehicle per
     * month for fuel, dated the month's first day in the horizon.
     */
    public function comingUp(Forecast $forecast): CsvTable
    {
        $money = static fn (?Money $m): ?string => $m === null ? null : CsvNumber::money($m->toDecimal(3), $m->currency);

        $rows = array_map(fn (ForecastItem $item): array => [
            $item->dueOn?->format('Y-m-d'),
            $item->vehicle->name(),
            $item->vehicle->data->registration,
            $this->wording->source($item->source),
            $this->wording->title($item),
            $money($item->cost),
            $item->currency,
            $this->yesNo($item->projected),
            $this->yesNo($item->overdue),
        ], $forecast->items());

        $months = $forecast->horizon->months();
        foreach ($forecast->fuel as $estimate) {
            foreach ($estimate->months as $i => $amount) {
                $rows[] = [
                    max($months[$i], $forecast->today())->format('Y-m-d'),
                    $estimate->vehicle->name(),
                    $estimate->vehicle->data->registration,
                    $this->t('coming_up.source.fuel'),
                    $this->t('coming_up.csv.fuel_title', ['month' => $months[$i]->format('Y-m')]),
                    $money($amount),
                    $estimate->currency,
                    $this->yesNo(true),
                    $this->yesNo(false),
                ];
            }
        }

        return new CsvTable(
            sprintf('logbook-coming-up-%s.csv', $forecast->today()->format('Y-m-d')),
            $this->headers([
                'export.column.date',
                'export.column.vehicle',
                'export.column.registration',
                'export.column.source',
                'export.column.title',
                'export.column.expected_cost',
                'export.column.currency',
                'export.column.projected',
                'export.column.overdue',
            ]),
            $rows,
        );
    }

    /**
     * The ownership report (spec.md §7.7 *Cost of ownership*): one row per
     * vehicle, section by section; a figure that cannot be worked out is
     * empty. Money per distance is in the owner's distance unit.
     */
    public function ownership(User $user, OwnershipReport $report, DateTimeImmutable $today): CsvTable
    {
        $unit = $user->preferences->distanceUnit;
        $unitName = ['unit' => $this->t('units.name.' . $unit->value)];
        $perUnit = ['unit' => $this->t('units.symbol.' . $unit->value)];
        $money = static fn (?Money $m): ?string
            => $m === null ? null : CsvNumber::money($m->toDecimal(3), $m->currency);
        $perDistance = static fn (?string $perKm): ?string => $perKm === null ? null : Decimal::trim(
            $unit === DistanceUnit::Mile ? Decimal::multiply($perKm, DistanceUnit::KM_PER_MILE_DECIMAL, 6) : $perKm,
        );

        $rows = array_map(fn (OwnershipCost $cost): array => [
            $cost->vehicle->name(),
            $cost->vehicle->data->registration,
            $cost->currency,
            $cost->period->from?->format('Y-m-d'),
            $cost->period->to->format('Y-m-d'),
            $this->t('ownership.start.' . $cost->start->value),
            $cost->vehicle->isWrittenOff()
                ? $this->t('export.written_off')
                : $this->yesNo($cost->vehicle->data->saleDate !== null),
            $cost->distanceKm === null ? null : CsvNumber::distance($cost->distanceKm, $unit),
            ...array_map(static fn (GroupTotal $g): ?string => $money($g->amount), $cost->groups),
            $money($cost->payouts),
            $money($cost->running),
            $money($cost->depreciationCost),
            $cost->valuedOn()?->format('Y-m-d'),
            $money($cost->total),
            $perDistance($cost->runningPerKm),
            $perDistance($cost->depreciationPerKm),
            $cost->perKmIsPartial ? null : $perDistance($cost->perKm),
            $money($cost->runningPerMonth),
            $money($cost->depreciationPerMonth),
            $cost->perMonthIsPartial ? null : $money($cost->perMonth),
        ], $report->rows());

        return new CsvTable(
            sprintf('logbook-ownership-%s.csv', $today->format('Y-m-d')),
            $this->headers([
                'export.column.vehicle',
                'export.column.registration',
                'export.column.currency',
                'export.column.owned_from',
                'export.column.owned_to',
                'export.column.started',
                'export.column.sold',
                ['export.column.distance_owned', $unitName],
                ...array_map(fn (CostGroup $g): array => [
                    'export.column.running_group',
                    ['group' => $this->t('expense.group.' . $g->value)],
                ], CostGroup::cases()),
                'export.column.insurance_payouts',
                'export.column.running',
                'export.column.depreciation',
                'export.column.depreciation_to',
                'export.column.total',
                ['export.column.running_per_distance', $perUnit],
                ['export.column.depreciation_per_distance', $perUnit],
                ['export.column.total_per_distance', $perUnit],
                'export.column.running_per_month',
                'export.column.depreciation_per_month',
                'export.column.total_per_month',
            ]),
            $rows,
        );
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function fuelTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = [];
        foreach ($this->fuel->listForVehicle($vehicle->id) as $entry) {
            $data = $entry->data;
            $electric = $data->fuel->isElectric();
            $rows[] = [
                $this->localDateTime($data->filledAt, $user),
                CsvNumber::distance($data->odometerKm, $prefs->distanceUnit),
                $this->t('fuel.fuel.' . $data->fuel->value),
                $data->grade === null ? null : $this->t($data->grade->labelKey()),
                $data->grade?->value,
                CsvNumber::volume($data->volume, $prefs->volumeUnit, $electric),
                $this->t('units.name.' . ($electric ? 'kwh' : $prefs->volumeUnit->value)),
                CsvNumber::unitPrice($data->pricePerUnit, $prefs->volumeUnit, $electric),
                CsvNumber::money($data->totalCost, $currency),
                $currency,
                $this->yesNo($data->isPartial),
                $this->yesNo($data->isMissedPrevious),
                $data->station,
                $data->notes,
            ];
        }

        return [$this->headers([
            ['export.column.date_time', ['zone' => $prefs->timezone]],
            ['export.column.odometer', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.fuel',
            'export.column.grade',
            'export.column.grade_code',
            'export.column.volume',
            'export.column.unit',
            'export.column.price_per_unit',
            'export.column.total',
            'export.column.currency',
            'export.column.partial',
            'export.column.missed_previous',
            'export.column.station',
            'export.column.notes',
        ]), $rows];
    }

    /**
     * Every tyre with where it is and how far it has gone (spec.md §7.17).
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function tyresTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $sets = TyreService::setsById($this->tyres->sets($vehicle));
        $rows = [];
        foreach ($this->tyres->views($vehicle, $user) as $view) {
            $wear = $view->wear;
            $tyre = $view->tyre;
            $data = $tyre->data;
            $set = $tyre->setId === null ? null : ($sets[$tyre->setId] ?? null);
            $rows[] = [
                $data->brand,
                $data->model,
                $data->size,
                $data->season === null ? null : $this->t('tyre.season.' . $data->season->value),
                $data->dot?->code,
                $data->dot?->manufacturedOn->format('Y-m-d'),
                $this->t('tyre.status.' . $tyre->status->value),
                $tyre->position === null ? null : $this->t('tyre.position.' . $tyre->position->value),
                $set?->data->name,
                $set?->data->storageLocation,
                CsvNumber::distance($view->distance->km, $prefs->distanceUnit),
                $tyre->retiredReason === null ? null : $this->t('tyre.reason.' . $tyre->retiredReason->value),
                $wear->latest === null ? null : CsvNumber::depth($wear->latest->treadMm, $prefs->depthUnit),
                $wear->latest?->doneOn->format('Y-m-d'),
                $wear->depthNowMm === null ? null : CsvNumber::depth($wear->depthNowMm, $prefs->depthUnit),
                $wear->kmLeft === null ? null : CsvNumber::distance($wear->kmLeft, $prefs->distanceUnit),
            ];
        }

        return [$this->headers([
            'export.column.brand',
            'export.column.model',
            'export.column.size',
            'export.column.season',
            'export.column.dot',
            'export.column.manufactured_on',
            'export.column.status',
            'export.column.position',
            'export.column.set',
            'export.column.storage_location',
            ['export.column.distance', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.retired_reason',
            ['export.column.latest_depth', ['unit' => $this->t('units.name.' . $prefs->depthUnit->value)]],
            'export.column.latest_depth_on',
            ['export.column.depth_now', ['unit' => $this->t('units.name.' . $prefs->depthUnit->value)]],
            ['export.column.distance_left', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
        ]), $rows];
    }

    /**
     * Every tyre change, oldest first, with the linked service record's cost.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function tyreChangesTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $tyres = [];
        foreach ($this->tyres->tyres($vehicle) as $tyre) {
            $tyres[$tyre->id] = $tyre;
        }
        $overview = $this->tyres->overview($vehicle, $user);
        $rows = [];
        foreach (array_reverse($overview->changes) as $item) {
            $change = $item->change;
            $names = array_map(static function (TyreChangeLine $line) use ($tyres): string {
                $data = ($tyres[$line->tyreId] ?? null)?->data;
                $name = $data?->name() ?? '';

                return $name !== '' ? $name : ($data->size ?? '');
            }, $change->lines);
            $positions = array_map(
                fn (TyreChangeLine $line): string => $line->position instanceof TyrePosition
                    ? $this->t('tyre.position.' . $line->position->value)
                    : '',
                $change->lines,
            );
            $rows[] = [
                $change->data->doneOn->format('Y-m-d'),
                $this->t('tyre.kind.' . $change->kind->value),
                $change->data->odometerKm === null ? null : CsvNumber::distance($change->data->odometerKm, $prefs->distanceUnit),
                implode('; ', $names),
                implode('; ', $positions),
                self::depths($change->lines, $prefs->depthUnit),
                $item->record?->data->title,
                $item->record === null ? null : CsvNumber::money($item->record->data->cost, $currency),
                $item->record === null ? null : $currency,
                $change->data->note,
            ];
        }

        return [$this->headers([
            'export.column.date',
            'export.column.kind',
            ['export.column.odometer', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.tyres',
            'export.column.positions',
            ['export.column.depths', ['unit' => $this->t('units.name.' . $prefs->depthUnit->value)]],
            'export.column.service_record',
            'export.column.cost',
            'export.column.currency',
            'export.column.note',
        ]), $rows];
    }

    /**
     * A change's depths, one per line in the tyres' order ("7.9; 8"; blank
     * for a tyre not measured), or null when none was measured.
     *
     * @param list<TyreChangeLine> $lines
     */
    private static function depths(array $lines, DepthUnit $unit): ?string
    {
        $depths = array_map(
            static fn (TyreChangeLine $line): string => $line->treadMm === null ? '' : CsvNumber::depth($line->treadMm, $unit),
            $lines,
        );

        return array_filter($depths, static fn (string $d): bool => $d !== '') === [] ? null : implode('; ', $depths);
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function odometerTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $rows = [];
        foreach ($this->odometer->listForVehicle($vehicle->id) as $reading) {
            $rows[] = [
                $this->localDateTime($reading->recordedAt, $user),
                CsvNumber::distance($reading->readingKm, $prefs->distanceUnit),
                $this->t('odometer.source.' . $reading->source->value),
                $reading->note,
            ];
        }

        return [$this->headers([
            ['export.column.date_time', ['zone' => $prefs->timezone]],
            ['export.column.odometer', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.source',
            'export.column.note',
        ]), $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function maintenanceTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = [];
        foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
            $data = $entry->data;
            $rows[] = [
                $data->performedOn->format('Y-m-d'),
                $this->t('maintenance.category.' . $data->category->value),
                $data->title,
                $data->odometerKm === null ? null : CsvNumber::distance($data->odometerKm, $prefs->distanceUnit),
                CsvNumber::money($data->cost, $currency),
                $currency,
                $data->vendor,
                $data->description,
            ];
        }

        return [$this->headers([
            'export.column.date',
            'export.column.category',
            'export.column.title',
            ['export.column.odometer', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.cost',
            'export.column.currency',
            'export.column.vendor',
            'export.column.details',
        ]), $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function documentsTable(User $user, Vehicle $vehicle): array
    {
        $prefs = $user->preferences;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = [];
        foreach ($this->documents->listForVehicle($vehicle->id) as $document) {
            $data = $document->data;
            $rows[] = [
                $this->t('compliance.type.' . $data->type->value),
                $data->title,
                $data->provider,
                $data->reference,
                $data->startOn?->format('Y-m-d'),
                $data->expiryOn?->format('Y-m-d'),
                $data->odometerKm === null ? null : CsvNumber::distance($data->odometerKm, $prefs->distanceUnit),
                CsvNumber::money($data->cost, $currency),
                $currency,
                $data->notes,
            ];
        }

        return [$this->headers([
            'export.column.type',
            'export.column.title',
            'export.column.provider',
            'export.column.reference',
            'export.column.start',
            'export.column.expiry',
            ['export.column.odometer', ['unit' => $this->t('units.name.' . $prefs->distanceUnit->value)]],
            'export.column.cost',
            'export.column.currency',
            'export.column.notes',
        ]), $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function expensesTable(User $user, Vehicle $vehicle): array
    {
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = [];
        foreach ($this->expenses->listForVehicle($vehicle->id) as $entry) {
            $data = $entry->data;
            $rows[] = [
                $data->spentOn->format('Y-m-d'),
                $this->t('expense.category.' . $data->category->value),
                CsvNumber::money($data->amount, $currency),
                $currency,
                $data->note,
            ];
        }

        return [$this->headers([
            'export.column.date',
            'export.column.category',
            'export.column.amount',
            'export.column.currency',
            'export.column.note',
        ]), $rows];
    }

    /**
     * Valuations oldest first (spec.md §7.7): values, not costs.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function valuationsTable(User $user, Vehicle $vehicle): array
    {
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $rows = [];
        foreach ($this->valuations->listForVehicle($vehicle->id) as $valuation) {
            $data = $valuation->data;
            $rows[] = [
                $data->valuedOn->format('Y-m-d'),
                CsvNumber::money($data->amount, $currency),
                $currency,
                $data->source,
                $data->notes,
            ];
        }

        return [$this->headers([
            'export.column.date',
            'export.column.amount',
            'export.column.currency',
            'export.column.source',
            'export.column.notes',
        ]), $rows];
    }

    /**
     * Every trip on the vehicle, oldest first (spec.md §7.13): the columns
     * the import reads back. Distances are in the owner's unit; the
     * distance is the whole trip.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function tripsTable(User $user, Vehicle $vehicle): array
    {
        $unit = $user->preferences->distanceUnit;
        $symbol = ['unit' => $this->t('units.symbol.' . $unit->value)];
        // Stored to 3 places in km, so 3 places in the owner's unit give back what was typed.
        $distance = static fn (string $km): string => Decimal::trim($unit->fromKmDecimal($km, 3));
        $rows = [];
        foreach (array_reverse($this->trips->listForVehicle($vehicle->id)) as $trip) {
            $data = $trip->data;
            $rows[] = [
                $data->travelledOn->format('Y-m-d'),
                $data->fromPlace,
                $data->toPlace,
                $this->yesNo($data->isReturn),
                $distance($data->distanceKm),
                $data->odometerStartKm === null ? null : $distance($data->odometerStartKm),
                $data->odometerEndKm === null ? null : $distance($data->odometerEndKm),
                $this->yesNo($data->isBusiness),
                $data->purpose,
                (string) $data->passengers,
                $data->notes,
            ];
        }

        return [$this->headers([
            'export.column.date',
            'export.column.from',
            'export.column.to',
            'export.column.return',
            ['export.column.distance', $symbol],
            ['export.column.odometer_start', $symbol],
            ['export.column.odometer_end', $symbol],
            'export.column.business',
            'export.column.purpose',
            'export.column.passengers',
            'export.column.notes',
        ]), $rows];
    }

    /**
     * The vehicle's incidents, oldest first (spec.md §7.13): every field but
     * the other party; the odometer in the owner's unit; links as the
     * linked records' ids. Exporting is `Manage`, which sees every detail.
     *
     * @return array{0: list<string>, 1: list<list<string|null>>}
     */
    private function incidentsTable(User $user, Vehicle $vehicle): array
    {
        $unit = $user->preferences->distanceUnit;
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $readings = [];
        foreach ($this->odometer->listForVehicle($vehicle->id) as $reading) {
            if ($reading->incidentId !== null) {
                $readings[$reading->incidentId] = Decimal::trim($unit->fromKmDecimal($reading->readingKm, 3));
            }
        }
        $links = $this->incidents->linksForVehicle($vehicle->id);
        $money = static fn (?string $amount): ?string => $amount === null ? null : CsvNumber::money($amount, $currency);
        $rows = [];
        foreach (array_reverse($this->incidents->listForVehicle($vehicle->id)) as $incident) {
            $data = $incident->data;
            $claim = $data->claim;
            $linked = $links[$incident->id] ?? [];
            $rows[] = [
                $data->occurredOn->format('Y-m-d'),
                $data->occurredAtTime,
                $data->location,
                $this->t($data->type->labelKey()),
                $this->t($data->fault->labelKey()),
                $data->description,
                implode('; ', array_map(fn ($area): string => $this->t($area->labelKey()), $data->damageAreas)),
                $data->severity === null ? null : $this->t($data->severity->labelKey()),
                $readings[$incident->id] ?? null,
                $data->driverUserId === null ? $data->driverName : $this->directory->displayName($data->driverUserId),
                $data->policeReference,
                $this->t($data->status->labelKey()),
                $data->closedOn?->format('Y-m-d'),
                $data->writeOff->isWrittenOff() ? $this->t($data->writeOff->labelKey()) : null,
                $this->t($claim->status->labelKey()),
                $claim->insurer,
                $claim->claimNumber,
                $money($claim->excess),
                $money($claim->payout),
                $currency,
                $this->t($claim->ncdAffected->labelKey()),
                $claim->updatedOn?->format('Y-m-d'),
                $data->notes,
                self::ids($linked['maintenance'] ?? []),
                self::ids($linked['expense'] ?? []),
                self::ids($linked['tyre'] ?? []),
            ];
        }

        return [$this->headers([
            'export.column.date',
            'incident.column.time',
            'incident.column.location',
            'incident.column.type',
            'incident.column.fault',
            'incident.column.description',
            'incident.column.damage',
            'incident.column.severity',
            ['export.column.odometer', ['unit' => $this->t('units.symbol.' . $unit->value)]],
            'incident.column.driver',
            'incident.column.police_reference',
            'incident.column.status',
            'incident.column.closed_on',
            'incident.column.write_off',
            'incident.column.claim_status',
            'incident.column.insurer',
            'incident.column.claim_number',
            'incident.column.excess',
            'incident.column.payout',
            'export.column.currency',
            'incident.column.ncd',
            'incident.column.claim_updated_on',
            'export.column.notes',
            'incident.column.linked_maintenance',
            'incident.column.linked_expenses',
            'incident.column.linked_tyre_changes',
        ]), $rows];
    }

    /**
     * @param list<int> $ids
     */
    private static function ids(array $ids): ?string
    {
        return $ids === [] ? null : implode('; ', $ids);
    }

    /**
     * A claim report's rows, oldest first (spec.md §7.23): distances in each
     * rate set's unit, amounts as plain decimals; a trip that crosses the
     * threshold gives both rates ("100 @ 0.55; 50 @ 0.25").
     */
    public function claim(ClaimReport $report): CsvTable
    {
        $rows = [];
        foreach ($report->rows as $row) {
            $data = $row->trip->data;
            $vehicle = $report->vehicle($row->trip->vehicleId);
            $unit = $row->unit();
            $rate = null;
            if ($row->isValued()) {
                $rate = $row->isSplit()
                    ? implode('; ', array_map(
                        static fn (ClaimLine $line): string
                            => Decimal::trim($line->distance) . ' @ ' . Decimal::trim($line->rate),
                        $row->lines,
                    ))
                    : Decimal::trim($row->lines[0]->rate);
            }
            $amount = $row->amount();
            $currency = $row->currency();
            $rows[] = [
                $data->travelledOn->format('Y-m-d'),
                $vehicle?->name(),
                $vehicle?->data->registration,
                $row->trip->journey(),
                $data->purpose,
                $row->distance === null ? null : Decimal::trim($row->distance),
                $unit === null ? null : $this->t('units.symbol.' . $unit->value),
                (string) $data->passengers,
                $rate,
                $row->passengerAmount === null || $currency === null ? null : CsvNumber::money($row->passengerAmount, $currency),
                $amount === null || $currency === null ? null : CsvNumber::money($amount, $currency),
                $currency,
            ];
        }

        return new CsvTable(
            sprintf('mileage-claim-%s.csv', $report->filter->slug()),
            $this->headers([
                'export.column.date',
                'export.column.vehicle',
                'export.column.registration',
                'export.column.journey',
                'export.column.purpose',
                'export.column.distance_plain',
                'export.column.unit',
                'export.column.passengers',
                'export.column.rate',
                'export.column.passenger_amount',
                'export.column.amount',
                'export.column.currency',
            ]),
            $rows,
        );
    }

    /**
     * The claims history (spec.md §7.29): what an insurer asks, never the
     * other party. A row whose details the user may not see keeps its date,
     * vehicle and type, the rest empty.
     */
    public function claimsHistory(ClaimsHistoryReport $report): CsvTable
    {
        $rows = [];
        foreach ($report->rows as $row) {
            $incident = $row->incident;
            $rows[] = [
                $incident->occurredOn->format('Y-m-d'),
                $row->vehicle->name(),
                $row->vehicle->data->registration,
                $this->t($incident->type->labelKey()),
                $incident->fault === null ? null : $this->t($incident->fault->labelKey()),
                $row->driver,
                $incident->claimStatus === null ? null : $this->t($incident->claimStatus->labelKey()),
                $incident->insurer,
                $incident->claimNumber,
                $incident->payout === null ? null : CsvNumber::money($incident->payout, $row->currency),
                $incident->payout === null ? null : $row->currency,
                $incident->ncdAffected === null ? null : $this->t($incident->ncdAffected->labelKey()),
                $incident->writeOff->isWrittenOff() ? $this->t($incident->writeOff->labelKey()) : null,
            ];
        }

        return new CsvTable(
            sprintf(
                'claims-history-%s-to-%s.csv',
                $report->from?->format('Y-m-d') ?? 'start',
                $report->until->format('Y-m-d'),
            ),
            $this->headers([
                'export.column.date',
                'export.column.vehicle',
                'export.column.registration',
                'incident.column.type',
                'incident.column.fault',
                'incident.column.driver',
                'incident.column.claim_status',
                'incident.column.insurer',
                'incident.column.claim_number',
                'incident.column.payout',
                'export.column.currency',
                'incident.column.ncd',
                'incident.column.write_off',
            ]),
            $rows,
        );
    }

    /**
     * @param list<string|array{0: string, 1: array<string, string>}> $keys
     * @return list<string>
     */
    private function headers(array $keys): array
    {
        return array_map(
            fn (string|array $key): string => is_array($key) ? $this->t($key[0], $key[1]) : $this->t($key),
            $keys,
        );
    }

    private function localDateTime(DateTimeInterface $instant, User $user): string
    {
        return LocalTime::fromUtc(DateTimeImmutable::createFromInterface($instant), $user->preferences->timeZone())
            ->format('Y-m-d H:i');
    }

    private function yesNo(bool $value): string
    {
        return $this->t($value ? 'export.yes' : 'export.no');
    }

    /**
     * @param array<string, string> $params
     */
    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
