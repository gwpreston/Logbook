<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Trip\TripData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Repository\TripRepository;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\DecimalParser;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV import (spec.md §7.13). Each row is read the way the module's form
 * reads what the owner types — the same parser, units, currency, time zone
 * and messages — after its dates, choices and yes/no values are put into
 * the form's shape. Nothing is written until import(), which saves every
 * importable row through the module's service in one transaction.
 */
final readonly class CsvImporter
{
    public const int MAX_ROWS = 5000;
    /** Places kept when converting to km and litres: the forms then round to what is stored. */
    private const int SI_SCALE = 6;

    public function __construct(
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private FuelEntryRepository $fuelEntries,
        private OdometerReadingRepository $readings,
        private MaintenanceEntryRepository $maintenanceEntries,
        private ComplianceDocumentRepository $documents,
        private ExpenseEntryRepository $expenseEntries,
        private VehicleService $vehicles,
        private TripService $trips,
        private TripRepository $tripEntries,
        private ClockInterface $clock,
        private Transaction $transaction,
        private TranslatorInterface $translator,
    ) {
    }

    public function vocabulary(User $user): ImportVocabulary
    {
        return new ImportVocabulary($this->translator, $user->preferences->locale);
    }

    /**
     * What importing the file would do, row by row (the dry run).
     */
    public function analyse(
        User $user,
        Vehicle $vehicle,
        ExportModule $module,
        CsvReader $csv,
        ImportOptions $options,
    ): ImportPreview {
        $fields = ImportField::forModule($module);
        $vocabulary = $this->vocabulary($user);
        $currency = $this->vehicles->currencyFor($user, $vehicle);
        $seen = array_fill_keys($this->existingKeys($vehicle, $module), true);
        // A fill-up without a fuel column is the vehicle's usual fuel.
        $defaults = $module === ExportModule::Fuel ? ['fuel' => Fuel::defaultFor($vehicle->data->fuelType)->value] : [];

        $rows = [];
        foreach ($csv->rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $column = $options->column($field->key);
                if ($column !== null) {
                    $values[$field->key] = trim($row['cells'][$column] ?? '');
                }
            }
            if (implode('', $values) === '') {
                continue;
            }

            $result = $this->readRow($module, $fields, $values, $defaults, $options, $vocabulary, $user->preferences, $currency);
            if ($result instanceof ImportRowStatus) {
                $rows[] = new ImportRow($row['line'], $result, $values);
                continue;
            }
            if (is_array($result)) {
                $rows[] = new ImportRow($row['line'], ImportRowStatus::Invalid, $values, $result);
                continue;
            }

            $key = DuplicateKey::of($result);
            if (isset($seen[$key])) {
                $rows[] = new ImportRow($row['line'], ImportRowStatus::Duplicate, $values);
                continue;
            }
            $seen[$key] = true;
            $rows[] = new ImportRow($row['line'], ImportRowStatus::Import, $values, [], $result);
        }

        return new ImportPreview($rows);
    }

    /**
     * Save every importable row, in one transaction.
     */
    public function import(
        User $user,
        Vehicle $vehicle,
        ExportModule $module,
        CsvReader $csv,
        ImportOptions $options,
    ): ImportPreview {
        $preview = $this->analyse($user, $vehicle, $module, $csv, $options);
        $zone = $user->preferences->timeZone();

        $fills = [];
        $this->transaction->run(function () use ($preview, $vehicle, $zone, &$fills): void {
            foreach ($preview->withStatus(ImportRowStatus::Import) as $row) {
                $data = $row->data;
                match (true) {
                    $data instanceof FuelEntryData => $fills[] = $this->fuel->create($vehicle, $data)->id,
                    $data instanceof OdometerReadingData => $this->odometer->create($vehicle, $data),
                    $data instanceof MaintenanceEntryData => $this->maintenance->create($vehicle, $data, $zone),
                    $data instanceof ComplianceDocumentData => $this->compliance->create($vehicle, $data, $zone),
                    $data instanceof ExpenseEntryData => $this->expenses->create($vehicle, $data),
                    $data instanceof TripData => $this->trips->create($vehicle, $data),
                    default => throw new LogicException('Unexpected import row.'),
                };
            }
        });

        if ($fills === []) {
            return $preview;
        }

        // Imports are where most typing mistakes arrive: count the imported
        // fill-ups the economy check flags.
        $checks = $this->fuel->checks($this->fuel->history($vehicle));

        return new ImportPreview($preview->rows, $checks->flaggedAmong($fills));
    }

    /**
     * One row: the data to save, the errors that stop it, or the status
     * that skips it.
     *
     * Quantities are converted to kilometres and litres here, precisely
     * (the export writes 6 places), and handed to the form in those units:
     * the form rounds to the stored precision, so an exported file imports
     * back to exactly the stored values whatever the owner's units.
     *
     * @param list<ImportField> $fields
     * @param array<string, string> $values
     * @param array<string, string> $defaults form values for fields the file leaves blank
     * @return object|ImportRowStatus|list<array{
     *     field: string, key: string, params: array<string, int|string|TranslatableInterface>
     * }>
     */
    private function readRow(
        ExportModule $module,
        array $fields,
        array $values,
        array $defaults,
        ImportOptions $options,
        ImportVocabulary $vocabulary,
        DisplayPreferences $owner,
        string $currency,
    ): object|array {
        $input = $defaults;
        $errors = [];

        // The row's own unit, and electricity (always kWh), decide how volumes read.
        $volumeUnit = $options->volumeUnit;
        $unitText = $values['unit'] ?? '';
        if ($unitText !== '') {
            $unit = $vocabulary->volumeUnit($unitText);
            if ($unit === null) {
                $errors[] = ['field' => 'unit', 'key' => 'import.error.unit', 'params' => ['unit' => $unitText]];
            } elseif ($unit instanceof VolumeUnit) {
                $volumeUnit = $unit;
            }
        }
        $fuelText = $values['fuel'] ?? '';
        $fuelCode = $fuelText === '' ? ($defaults['fuel'] ?? '') : $vocabulary->choice(Fuel::class, 'fuel.fuel.', $fuelText);
        $fuel = Fuel::tryFrom($fuelCode ?? '');
        $electric = $fuel?->isElectric() ?? false;

        foreach ($fields as $field) {
            $value = $values[$field->key] ?? '';
            if ($value === '') {
                if ($field->default !== null) {
                    $input[$field->key] = $field->default;
                }
                continue;
            }

            switch ($field->kind) {
                case FieldKind::Date:
                    $input[$field->key] = $options->dateOrder->normalise($value) ?? $value;
                    break;
                case FieldKind::DateTime:
                    $input[$field->key] = $this->dateTime($value, $options->dateOrder);
                    break;
                case FieldKind::Choice:
                    assert($field->enum !== null && $field->labelPrefix !== null);
                    $input[$field->key] = $vocabulary->choice($field->enum, $field->labelPrefix, $value) ?? $value;
                    break;
                case FieldKind::Flag:
                    $flag = $vocabulary->flag($value);
                    if ($flag === null) {
                        $errors[] = ['field' => $field->key, 'key' => 'import.error.yes_no', 'params' => []];
                    }
                    $input[$field->key] = $flag === true ? '1' : '';
                    break;
                case FieldKind::Currency:
                    if (strtoupper($value) !== $currency) {
                        $errors[] = [
                            'field' => $field->key,
                            'key' => 'import.error.currency',
                            'params' => ['currency' => strtoupper($value), 'vehicle' => $currency],
                        ];
                    }
                    break;
                case FieldKind::Source:
                    assert($field->enum !== null && $field->labelPrefix !== null);
                    $source = $vocabulary->choice($field->enum, $field->labelPrefix, $value);
                    // Tyre history is not imported, so a tyre change's reading is kept as a
                    // manual one (spec.md §7.13); the other owners write theirs on import.
                    $owned = [OdometerSource::Manual->value, OdometerSource::Tyre->value];
                    if ($source !== null && !in_array($source, $owned, true)) {
                        return ImportRowStatus::Implied;
                    }
                    break;
                case FieldKind::Distance:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null
                        ? $value
                        : $options->distanceUnit->toKmDecimal($number, self::SI_SCALE);
                    break;
                case FieldKind::Volume:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null || $electric
                        ? $value
                        : $volumeUnit->toLitresDecimal($number, self::SI_SCALE);
                    break;
                case FieldKind::UnitPrice:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null || $electric
                        ? $value
                        : $volumeUnit->pricePerLitre($number, self::SI_SCALE + 3);
                    break;
                case FieldKind::Grade:
                    // The form checks that it belongs to the row's fuel.
                    $grade = $vocabulary->grade($value);
                    if ($grade === null) {
                        $errors[] = ['field' => $field->key, 'key' => 'import.error.grade', 'params' => ['grade' => $value]];
                    }
                    $input[$field->key] = $grade->value ?? '';
                    break;
                case FieldKind::VolumeUnit:
                    break;
                case FieldKind::Number:
                case FieldKind::Text:
                    $input[$field->key] = $value;
                    break;
            }
        }

        // Quantities are in km and litres now; times in the file's zone.
        $preferences = new DisplayPreferences(
            $owner->locale,
            $options->timezone,
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
        );
        $parsed = match ($module) {
            ExportModule::Fuel => FuelEntryForm::parse($input, $preferences, $currency),
            ExportModule::Odometer => OdometerReadingForm::parse($input, $preferences),
            ExportModule::Maintenance => MaintenanceEntryForm::parse($input, $preferences, []),
            ExportModule::Documents => ComplianceDocumentForm::parse($input, $preferences),
            ExportModule::Expenses => ExpenseEntryForm::parse($input, $preferences),
            ExportModule::Trips => TripForm::parse(
                $input,
                $preferences,
                LocalTime::today($this->clock, $owner->timeZone()),
                wholeDistance: true,
            ),
            ExportModule::Tyres,
            ExportModule::TyreChanges,
            ExportModule::Valuations,
            ExportModule::Incidents,
            ExportModule::Finance
                => throw new LogicException($module->value . ' are not imported.'),
        };

        if ($parsed instanceof ValidationErrors) {
            foreach ($parsed->all() as $field => $error) {
                $errors[] = ['field' => $field, 'key' => $error['key'], 'params' => $error['params']];
            }
        }

        return $errors === [] ? $parsed : $errors;
    }

    /**
     * A local date and time in the form's shape ("2026-09-27T14:30"); a date
     * alone is taken as noon. Anything unreadable goes through unchanged, for
     * the form's own error.
     */
    private function dateTime(string $value, DateOrder $order): string
    {
        if (preg_match('/^(\S+?)(?:[T ]+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', trim($value), $m) !== 1) {
            return $value;
        }
        $date = $order->normalise($m[1]);
        if ($date === null) {
            return $value;
        }
        if (!isset($m[2]) || $m[2] === '') {
            return $date . 'T12:00';
        }

        return sprintf('%sT%02d:%s', $date, (int) $m[2], $m[3] ?? '00');
    }

    /**
     * Identity of the entries already logged for the vehicle, to spot duplicates.
     *
     * @return list<string>
     */
    private function existingKeys(Vehicle $vehicle, ExportModule $module): array
    {
        return match ($module) {
            ExportModule::Fuel => array_map(
                static fn ($e): string => DuplicateKey::of($e->data),
                $this->fuelEntries->listForVehicle($vehicle->id),
            ),
            // Every reading counts, whatever its source: a fill-up already
            // imported has written the reading an odometer file repeats.
            ExportModule::Odometer => array_map(
                static fn ($r): string => DuplicateKey::of(new OdometerReadingData($r->readingKm, $r->recordedAt)),
                $this->readings->listForVehicle($vehicle->id),
            ),
            ExportModule::Maintenance => array_map(
                static fn ($e): string => DuplicateKey::of($e->data),
                $this->maintenanceEntries->listForVehicle($vehicle->id),
            ),
            ExportModule::Documents => array_map(
                static fn ($d): string => DuplicateKey::of($d->data),
                $this->documents->listForVehicle($vehicle->id),
            ),
            ExportModule::Expenses => array_map(
                static fn ($e): string => DuplicateKey::of($e->data),
                $this->expenseEntries->listForVehicle($vehicle->id),
            ),
            ExportModule::Trips => array_map(
                static fn ($t): string => DuplicateKey::of($t->data),
                $this->tripEntries->listForVehicle($vehicle->id),
            ),
            ExportModule::Tyres,
            ExportModule::TyreChanges,
            ExportModule::Valuations,
            ExportModule::Incidents,
            ExportModule::Finance
                => throw new LogicException($module->value . ' are not imported.'),
        };
    }
}
