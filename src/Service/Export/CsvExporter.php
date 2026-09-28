<?php

declare(strict_types=1);

namespace Logbook\Service\Export;

use DateTimeImmutable;
use DateTimeInterface;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Expense\CostItem;
use Logbook\Service\Report\Report;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvNumber;
use Logbook\Support\Csv\CsvTable;
use Logbook\Support\Date\LocalTime;
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
