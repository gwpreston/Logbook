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
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\Report;
use Logbook\Service\Tyre\TyreService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvNumber;
use Logbook\Support\Csv\CsvTable;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Units\DepthUnit;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV exports (spec.md §7.7): one table per vehicle and module, and the
 * ledger lines of a report. Headers are in the owner's language with the
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
