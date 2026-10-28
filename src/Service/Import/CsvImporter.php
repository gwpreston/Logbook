<?php

declare(strict_types=1);

namespace Logbook\Service\Import;

use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Odometer\OdometerSource;
use Logbook\Domain\Station\StationName;
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
use Logbook\Service\Fuel\FuelAmounts;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerReadingForm;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Trip\TripForm;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Csv\CsvWriter;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Date\WallClock;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\DecimalParser;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use DateTimeZone;
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
        private StationService $stations,
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
        $notes = [];
        foreach ($csv->rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $column = $options->column($field->key);
                if ($column !== null) {
                    // Logbook's own export guards formulas with a `'` (#455).
                    $values[$field->key] = trim(CsvWriter::unguard(trim($row['cells'][$column] ?? '')));
                }
            }
            if (implode('', $values) === '') {
                continue;
            }

            $warning = null;
            $result = $this->readRow(
                $module,
                $fields,
                $values,
                $defaults,
                $options,
                $vocabulary,
                $user->preferences,
                $currency,
                $warning,
            );
            if ($result instanceof ImportRowStatus) {
                $rows[] = new ImportRow($row['line'], $result, $values);
                continue;
            }
            if (is_array($result)) {
                $rows[] = new ImportRow($row['line'], ImportRowStatus::Invalid, $values, $result);
                continue;
            }

            $key = DuplicateKey::of($result);
            if (isset($seen[$key]) || $this->atOtherInstant($result, $warning, $options, $seen)) {
                $rows[] = new ImportRow($row['line'], ImportRowStatus::Duplicate, $values);
                continue;
            }
            $seen[$key] = true;
            $note = $this->stationNote($result, $notes);
            $rows[] = new ImportRow($row['line'], ImportRowStatus::Import, $values, [], $result, $note, $warning);
        }

        return new ImportPreview($rows);
    }

    /**
     * Whether a row whose time is the first of a repeated clock-change time already has its
     * entry at the other instant: an old-format export wrote both as the same text, so the
     * later one reads as the first and would land an hour early (K147, #561).
     *
     * @param array{key: string, params: array<string, string>}|null $warning
     * @param array<string, true> $seen
     */
    private function atOtherInstant(object $result, ?array $warning, ImportOptions $options, array $seen): bool
    {
        if ($warning === null || $warning['key'] !== 'import.warning.time_repeated') {
            return false;
        }
        $first = match (true) {
            $result instanceof FuelEntryData => $result->filledAt,
            $result instanceof OdometerReadingData => $result->recordedAt,
            default => null,
        };
        if ($first === null) {
            return false;
        }
        $other = LocalTime::otherOfRepeated($first, new DateTimeZone($options->timezone));

        return isset($seen[DuplicateKey::of($result, $other)]);
    }

    /**
     * Which station an importable fill-up will be linked to (spec.md §7.33
     * *CSV import*): an existing one by name, or a new one. None for home
     * charging, a row without a station, or with the module off. Each name
     * is looked up once per file (K49).
     *
     * @param array<string, array{key: string, params: array<string, string>}> $notes by tidied name
     * @return array{key: string, params: array<string, string>}|null
     */
    private function stationNote(object $data, array &$notes): ?array
    {
        if (!$data instanceof FuelEntryData || $data->station === null || !$this->stations->enabled()) {
            return null;
        }
        $name = StationName::tidy($data->station);
        if ($name === '' || $data->grade === FuelGrade::Home) {
            return null;
        }
        if (isset($notes[$name])) {
            return $notes[$name];
        }
        $existing = $this->stations->existing($name);

        return $notes[$name] = $existing === null
            ? ['key' => 'stations.import.new', 'params' => ['name' => $name]]
            : ['key' => 'stations.import.links', 'params' => ['name' => $existing->data->name]];
    }

    /**
     * Save every importable row, in one transaction. $preview is the
     * analysis of this same file and options when the caller has just run
     * it, so a 5,000-row file isn't read twice (K49).
     */
    public function import(
        User $user,
        Vehicle $vehicle,
        ExportModule $module,
        CsvReader $csv,
        ImportOptions $options,
        ?ImportPreview $preview = null,
    ): ImportPreview {
        $preview ??= $this->analyse($user, $vehicle, $module, $csv, $options);
        $zone = $user->preferences->timeZone();

        $fills = [];
        $this->transaction->run(function () use ($preview, $vehicle, $zone, &$fills): void {
            $rows = $preview->withStatus(ImportRowStatus::Import);
            // Fill-ups in one pass, the slowest module at 5,000 rows (K49).
            $fuel = array_values(array_filter(
                array_map(static fn (ImportRow $row): ?object => $row->data, $rows),
                static fn (?object $data): bool => $data instanceof FuelEntryData,
            ));
            $fills = $this->fuel->import($vehicle, $fuel);
            foreach ($rows as $row) {
                $data = $row->data;
                match (true) {
                    $data instanceof FuelEntryData => null,
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
     * Quantities are converted to kilometres and litres here, straight to
     * the places stored, and handed to the form in those units: rounded
     * once, as the form rounds what is typed in miles or gallons (K21), and
     * an exported file (6 places) imports back to exactly the stored values
     * whatever the owner's units.
     *
     * @param list<ImportField> $fields
     * @param array<string, string> $values
     * @param array<string, string> $defaults form values for fields the file leaves blank
     * @param array{key: string, params: array<string, string>}|null $warning set when a time is taken as the first of two
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
        ?array &$warning = null,
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
        // kWh and kg (CNG) read as they are, whatever the file's volume unit.
        $electric = $fuel !== null && !$fuel->kind()->followsVolumeUnit();

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
                    // A time with no offset is read in the file's zone: say when that zone
                    // has no such time, or has it twice (K13, #561).
                    $zone = new DateTimeZone($options->timezone);
                    switch (LocalTime::wallClock($input[$field->key], $zone)) {
                        case WallClock::Skipped:
                            $errors[] = [
                                'field' => $field->key,
                                'key' => 'import.error.time_skipped',
                                'params' => ['time' => $value],
                            ];
                            break;
                        case WallClock::Repeated:
                            $warning = ['key' => 'import.warning.time_repeated', 'params' => ['time' => $value]];
                            // The first of the two, with its offset, so the form reads it exactly.
                            $first = LocalTime::firstOfRepeated($input[$field->key], $zone);
                            if ($first !== null) {
                                $input[$field->key] = $first->setTimezone($zone)->format('Y-m-d\TH:i:sP');
                            }
                            break;
                        case WallClock::Exists:
                            break;
                    }
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
                    // Tyre history and vehicle details are not imported, so a tyre change's
                    // reading and the mileage when bought are kept as manual ones (spec.md
                    // §7.13); the other owners write theirs on import.
                    // Issues are not imported either (Phase 40.1), so theirs are kept likewise.
                    $owned = [
                        OdometerSource::Manual->value,
                        OdometerSource::Tyre->value,
                        OdometerSource::Purchase->value,
                        OdometerSource::Issue->value,
                        OdometerSource::IssueUpdate->value,
                        // MOT tests are fetched, not imported (Phase 41).
                        OdometerSource::Mot->value,
                        // Modifications and parts are not imported either (Phase 50.1).
                        OdometerSource::Modification->value,
                        OdometerSource::ModificationRevert->value,
                        OdometerSource::PartFitted->value,
                        OdometerSource::PartRemoved->value,
                    ];
                    if ($source !== null && !in_array($source, $owned, true)) {
                        return ImportRowStatus::Implied;
                    }
                    break;
                case FieldKind::Distance:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null
                        ? $value
                        : $options->distanceUnit->toKmDecimal($number, OdometerReadingForm::KM_SCALE);
                    break;
                case FieldKind::Volume:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null || $electric
                        ? $value
                        : $volumeUnit->toLitresDecimal($number, FuelAmounts::VOLUME_SCALE);
                    break;
                case FieldKind::UnitPrice:
                    $number = DecimalParser::parse($value, $owner->locale);
                    $input[$field->key] = $number === null || $electric
                        ? $value
                        : $volumeUnit->pricePerLitre($number, FuelAmounts::PRICE_SCALE);
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
            ExportModule::Issues,
            ExportModule::MotTests,
            ExportModule::Modifications,
            ExportModule::Parts,
            ExportModule::PartFittings,
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
        // No date and time is this long; a longer cell would only feed the pattern below (ReDoS).
        if (strlen($value) > 40) {
            return $value;
        }
        if (preg_match('/^(\S+?)(?:[T ]+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(Z|[+-]\d{2}:?\d{2})?)?$/i', trim($value), $m) !== 1) {
            return $value;
        }
        $date = $order->normalise($m[1]);
        if ($date === null) {
            return $value;
        }
        if (!isset($m[2]) || $m[2] === '') {
            return $date . 'T12:00';
        }

        // An offset (`+01:00`, `Z`) is kept for the form to read: the time is then exact (K13).
        return sprintf('%sT%02d:%s%s', $date, (int) $m[2], $m[3] ?? '00', $m[5] ?? '');
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
            ExportModule::Issues,
            ExportModule::MotTests,
            ExportModule::Modifications,
            ExportModule::Parts,
            ExportModule::PartFittings,
            ExportModule::Finance
                => throw new LogicException($module->value . ' are not imported.'),
        };
    }
}
