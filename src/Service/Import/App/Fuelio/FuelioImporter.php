<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

use DateTimeImmutable;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Station\Station;
use Logbook\Domain\Station\StationData;
use Logbook\Domain\Station\StationName;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\ExpenseEntryRepository;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\ImportSourceRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\StationRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Attachment\StoredFile;
use Logbook\Service\Expense\ExpenseEntryForm;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Import\App\AppArchive;
use Logbook\Service\Import\App\AppImportOptions;
use Logbook\Service\Import\App\AppRow;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\AppVehiclePreview;
use Logbook\Service\Import\App\UnitSanity;
use Logbook\Service\Import\DateOrder;
use Logbook\Service\Import\DuplicateKey;
use Logbook\Service\Import\ImportVocabulary;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Station\StationService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\Region;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Slim\Psr7\UploadedFile;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Imports a Fuelio export (spec.md §7.13 *Importing from another app*,
 * Phase 31). guess() proposes the mapping, analyse() previews every row of
 * every section with its outcome, and import() writes the importable rows
 * of one or more vehicles in one transaction, through the same forms and
 * services as typing them in.
 */
final readonly class FuelioImporter
{
    /** A fill-up's position matches an existing station within this distance. */
    public const float STATION_RADIUS_KM = 0.15;

    /**
     * Fuelio's built-in cost categories by id (the sample's list), and where
     * their rows go by default.
     */
    private const array BUILT_IN = [
        1 => 'maintenance:service',      // Service
        2 => 'maintenance:other',        // Maintenance
        4 => 'expense:tax',              // Registration
        5 => 'expense:parking',          // Parking
        6 => 'expense:cleaning',         // Wash
        7 => 'expense:tolls',            // Tolls
        8 => 'expense:fines',            // Tickets/Fines
        9 => 'expense:accessories',      // Tuning
        31 => 'expense:other',           // Insurance
    ];

    /** Places kept when converting to km and litres; the forms round to what is stored. */
    private const int SI_SCALE = 6;

    public function __construct(
        private FuelService $fuel,
        private MaintenanceService $maintenance,
        private ExpenseService $expenses,
        private ScheduleService $schedules,
        private VehicleService $vehicles,
        private StationService $stations,
        private StationRepository $stationRows,
        private FuelEntryRepository $fuelEntries,
        private MaintenanceEntryRepository $maintenanceEntries,
        private ExpenseEntryRepository $expenseEntries,
        private ImportSourceRepository $sources,
        private AttachmentService $attachments,
        private FileStorage $files,
        private FeatureToggles $features,
        private Transaction $transaction,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The mapping proposed for one vehicle of the export.
     *
     * @param list<Vehicle> $manageable the active vehicles the user can manage
     */
    public function guess(User $user, FuelioExport $export, array $manageable): AppImportOptions
    {
        $vocabulary = new ImportVocabulary($this->translator, $user->preferences->locale);
        $prefs = $user->preferences;

        // The vehicle already holding rows of this export, else one with its registration.
        $ids = array_map(static fn (Vehicle $v): int => $v->id, $manageable);
        $vehicle = $this->sources->vehicleHolding(FuelioReader::APP, self::guids($export), $ids);
        if ($vehicle === null && trim($export->vehicle->plate) !== '') {
            foreach ($manageable as $candidate) {
                if (self::plate($candidate->data->registration ?? '') === self::plate($export->vehicle->plate)) {
                    $vehicle = $candidate->id;
                    break;
                }
            }
        }
        $target = null;
        foreach ($manageable as $candidate) {
            if ($candidate->id === $vehicle) {
                $target = $candidate;
            }
        }

        $distance = $export->distanceText === null ? null : $vocabulary->distanceUnit($export->distanceText);
        $volume = $export->volumeText === null ? null : $vocabulary->volumeUnit($export->volumeText);

        $categories = [];
        foreach ($export->categoryIds() as $id) {
            $categories[$id] = $this->defaultCategory($id, $export->categories[$id] ?? '', $vocabulary);
        }
        $fuels = [];
        foreach ($export->fuelCodes() as $code) {
            $fuels[$code] = self::defaultFuel($code, $target)->value();
        }

        return new AppImportOptions(
            vehicle: $vehicle === null ? AppImportOptions::NEW_VEHICLE : (string) $vehicle,
            distanceUnit: $distance ?? $prefs->distanceUnit,
            volumeUnit: $volume instanceof VolumeUnit ? $volume : $prefs->volumeUnit,
            dateOrder: self::dateOrder($export->vehicle->dateFormat),
            categories: $categories,
            fuels: $fuels,
        );
    }

    /**
     * Whether the guess read the units from the file (else they are the
     * owner's, highlighted on the mapping page).
     */
    public function unitsFromFile(User $user, FuelioExport $export): bool
    {
        $vocabulary = new ImportVocabulary($this->translator, $user->preferences->locale);

        return $export->distanceText !== null && $vocabulary->distanceUnit($export->distanceText) !== null
            && $export->volumeText !== null && $vocabulary->volumeUnit($export->volumeText) instanceof VolumeUnit;
    }

    /**
     * Whether a fuel code or category id was recognised (else its default
     * is highlighted on the mapping page).
     */
    public static function knownFuelCode(int $code): bool
    {
        return intdiv($code, 100) === 1;
    }

    public static function knownCategory(int $id): bool
    {
        return isset(self::BUILT_IN[$id]);
    }

    /**
     * Every row of one vehicle of the export, with what importing it would do.
     *
     * @param Vehicle|null $target the existing vehicle the options name (checked by the caller); null for a new one
     */
    public function analyse(
        User $user,
        FuelioExport $export,
        AppImportOptions $options,
        ?Vehicle $target,
        ?AppArchive $archive = null,
    ): AppVehiclePreview {
        $currency = $target === null
            ? $user->preferences->currency
            : $this->vehicles->currencyFor($user, $target);
        $known = $target === null ? [] : $this->sources->known(FuelioReader::APP, $target->id);
        $preferences = self::canonical($user->preferences);

        $sections = [];
        $stationsByFuelioId = [];
        $sections[AppVehiclePreview::STATIONS] = $this->stationRows($export, $known, $stationsByFuelioId);
        [$sections[AppVehiclePreview::FILLS], $sanityFills, $sanityFigures] = $this->fillRows(
            $export,
            $options,
            $target,
            $known,
            $preferences,
            $currency,
            $stationsByFuelioId,
        );
        $sections[AppVehiclePreview::COSTS] = $this->costRows($export, $options, $target, $known, $preferences);
        $sections[AppVehiclePreview::SCHEDULES] = $options->schedules
            ? $this->scheduleRows($export, $options, $sections[AppVehiclePreview::COSTS])
            : [];
        $sections[AppVehiclePreview::PHOTOS] = $this->photoRows($export, $sections[AppVehiclePreview::FILLS], $archive);

        return new AppVehiclePreview(
            export: $export,
            options: $options,
            target: $target,
            newVehicle: $options->createsVehicle() ? $this->newVehicle($export, $options) : null,
            currency: $currency,
            sections: $sections,
            sanity: UnitSanity::of($sanityFills, $sanityFigures, $export->consumptionText),
        );
    }

    /**
     * Write every importable row of every previewed vehicle in one
     * transaction: if any of it fails, nothing is written (and the photo
     * files already stored are deleted again).
     *
     * @param list<AppVehiclePreview> $previews fresh from analyse()
     * @return list<AppVehiclePreview> the same, each with the vehicle written to
     */
    public function import(User $user, array $previews, ?AppArchive $archive = null): array
    {
        $storedPhotos = [];
        $fillIds = [];
        try {
            $written = $this->transaction->run(function () use ($user, $previews, $archive, &$storedPhotos, &$fillIds): array {
                $out = [];
                foreach ($previews as $index => $preview) {
                    if ($preview->isSkipped()) {
                        $out[$index] = null;
                        continue;
                    }
                    $fillIds[$index] = [];
                    $out[$index] = $this->importVehicle($user, $preview, $archive, $storedPhotos, $fillIds[$index]);
                }

                return $out;
            });
        } catch (Throwable $e) {
            foreach ($storedPhotos as $path) {
                $this->files->delete($path);
            }
            throw $e;
        }

        $results = [];
        foreach ($previews as $index => $preview) {
            $vehicle = $written[$index] ?? null;
            if ($vehicle === null) {
                $results[] = $preview;
                continue;
            }
            $checks = $this->fuel->checks($this->fuel->history($vehicle));
            $ids = $fillIds[$index];
            $results[] = $preview->done($vehicle, $ids === [] ? 0 : $checks->flaggedAmong($ids));
        }

        return $results;
    }

    /**
     * @param list<string> $storedPhotos paths of the photo files written so far
     * @param list<int> $fillIds the fill-ups written
     */
    private function importVehicle(
        User $user,
        AppVehiclePreview $preview,
        ?AppArchive $archive,
        array &$storedPhotos,
        array &$fillIds,
    ): Vehicle {
        $now = $this->clock->now();
        $vehicle = $preview->target;
        if ($vehicle === null) {
            $data = $preview->newVehicle ?? throw new \LogicException('No vehicle to import into.');
            $vehicle = $this->vehicles->create($user, $data);
        }
        $record = fn (AppRow $row, string $type, int $id) => $this->sources->record(
            FuelioReader::APP,
            $row->sourceId,
            $vehicle->id,
            $type,
            $id,
            $user->id,
            $now,
        );

        foreach ($preview->withStatus(AppVehiclePreview::STATIONS, AppRowStatus::Import) as $row) {
            $station = $row->extra['existing'] ?? null;
            if (!$station instanceof Station) {
                $data = $row->data;
                assert($data instanceof StationData);
                $station = $this->stations->create($user, $data);
            }
            $this->stations->setFavourite($user, $station, true);
            $record($row, 'station', $station->id);
        }

        $scheduleIds = [];
        foreach ($preview->withStatus(AppVehiclePreview::SCHEDULES, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof MaintenanceScheduleData);
            $schedule = $this->schedules->create($vehicle, $data);
            $scheduleIds[self::scheduleKey($data->category, $data->title)] = $schedule->id;
            $record($row, 'schedule', $schedule->id);
        }

        $zone = $user->preferences->timeZone();
        foreach ($preview->withStatus(AppVehiclePreview::COSTS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            if ($data instanceof MaintenanceEntryData) {
                $schedule = $scheduleIds[self::scheduleKey($data->category, $data->title)] ?? null;
                if ($schedule !== null) {
                    $data = new MaintenanceEntryData(
                        $data->performedOn,
                        $data->category,
                        $data->title,
                        $data->cost,
                        $data->odometerKm,
                        $data->vendor,
                        $data->description,
                        $schedule,
                    );
                }
                $record($row, 'maintenance', $this->maintenance->create($vehicle, $data, $zone)->id);
            } elseif ($data instanceof ExpenseEntryData) {
                $record($row, 'expense', $this->expenses->create($vehicle, $data)->id);
            }
        }

        $photos = [];
        foreach ($preview->withStatus(AppVehiclePreview::PHOTOS, AppRowStatus::Import) as $row) {
            $fill = $row->extra['fill'] ?? null;
            $file = $row->extra['file'] ?? null;
            if (is_string($fill) && is_string($file)) {
                $photos[$fill][] = $file;
            }
        }
        $fillIds = [];
        foreach ($preview->withStatus(AppVehiclePreview::FILLS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof FuelEntryData);
            $entry = $this->fuel->create($vehicle, $data);
            $fillIds[] = $entry->id;
            $record($row, 'fuel', $entry->id);
            $names = $photos[$row->sourceId] ?? [];
            if ($archive !== null && $names !== []) {
                $this->attachPhotos($vehicle, $entry->id, $names, $archive, $storedPhotos);
            }
        }

        return $vehicle;
    }

    /**
     * Store a fill-up's photos as `fuel` attachments, checked and stripped
     * as any upload is (spec.md §7.12).
     *
     * @param list<string> $names
     * @param list<string> $storedPhotos
     */
    private function attachPhotos(Vehicle $vehicle, int $fillId, array $names, AppArchive $archive, array &$storedPhotos): void
    {
        $pending = [];
        foreach ($names as $name) {
            $path = $archive->extractPhoto($name);
            if ($path === null) {
                continue;
            }
            $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');
            $file = new UploadedFile($path, $name, $mime, (int) filesize($path), UPLOAD_ERR_OK);
            $check = $this->attachments->check($file, AttachmentOwner::Fuel);
            if ($check->isValid()) {
                $pending[] = new PendingUpload($file, $check);
            }
        }
        foreach (array_chunk($pending, max(1, $this->attachments->maxFiles())) as $chunk) {
            $this->attachments->saveWithFiles(
                new PendingUploads($chunk),
                /** @param list<StoredFile> $stored */
                function (array $stored) use ($vehicle, $fillId, &$storedPhotos): void {
                    foreach ($stored as $file) {
                        $storedPhotos[] = $file->path;
                    }
                    $this->attachments->record($vehicle, AttachmentOwner::Fuel, $fillId, $stored);
                },
            );
        }
    }

    /**
     * Favourite stations: matched to an existing station by name, then
     * within 150 m, else new (spec.md §7.13 *Mapping rules*).
     *
     * @param array<string, true> $known
     * @param array<string, string> $byFuelioId Fuelio station id → the Logbook station's name, filled here
     * @return list<AppRow>
     */
    private function stationRows(FuelioExport $export, array $known, array &$byFuelioId): array
    {
        $rows = [];
        $enabled = $this->stations->enabled();
        foreach ($export->stations as $station) {
            $summary = implode(' · ', array_filter([$station->name, $station->description]));
            $name = mb_substr(StationName::tidy($station->name), 0, 100);
            if ($name === '') {
                $rows[] = new AppRow($station->line, AppRowStatus::Invalid, $station->guid, $summary, [
                    ['field' => 'name', 'key' => 'validation.required', 'params' => []],
                ]);
                continue;
            }
            $existing = $this->matchStation($name, $station->position());
            if ($station->stationId !== '') {
                $byFuelioId[$station->stationId] = $existing?->data->name ?? $name;
            }
            if (!$enabled) {
                $rows[] = new AppRow(
                    $station->line,
                    AppRowStatus::NotImported,
                    $station->guid,
                    $summary,
                    note: self::note('import_app.reason.stations_off'),
                );
                continue;
            }
            if (isset($known[$station->guid])) {
                $rows[] = new AppRow($station->line, AppRowStatus::AlreadyImported, $station->guid, $summary);
                continue;
            }
            $position = $station->position();
            $data = new StationData(
                name: $name,
                address: $station->description === '' || $station->description === $station->name
                    ? null
                    : mb_substr($station->description, 0, 300),
                country: Region::fromAlpha3($station->countryCode),
                latitude: $position === null ? null : Decimal::fromFloat($position[0], 6),
                longitude: $position === null ? null : Decimal::fromFloat($position[1], 6),
            );
            $rows[] = new AppRow(
                $station->line,
                AppRowStatus::Import,
                $station->guid,
                $summary,
                data: $data,
                note: $existing === null
                    ? self::note('stations.import.new', ['name' => $name])
                    : self::note('stations.import.links', ['name' => $existing->data->name]),
                extra: ['existing' => $existing],
            );
        }

        return $rows;
    }

    /**
     * @param array<string, true> $known
     * @param array<string, string> $stationsByFuelioId
     * @return array{0: list<AppRow>, 1: list<FuelEntryData>, 2: list<string>} the rows, and the
     *         fill-ups and the app's figures for the sanity line
     */
    private function fillRows(
        FuelioExport $export,
        AppImportOptions $options,
        ?Vehicle $target,
        array $known,
        DisplayPreferences $preferences,
        string $currency,
        array $stationsByFuelioId,
    ): array {
        $seen = $target === null ? [] : array_fill_keys(array_map(
            static fn ($e): string => DuplicateKey::of($e->data),
            $this->fuelEntries->listForVehicle($target->id),
        ), true);
        $rows = [];
        $sanity = [];
        $figures = [];

        foreach ($export->fills as $fill) {
            $summary = implode(' · ', array_filter([$fill->date, $fill->odometer, $fill->volume, $fill->total, $fill->city]));
            $extra = ['unique_id' => $fill->uniqueId];
            $choice = $options->fuelTarget($fill->fuelCode);
            if ($choice === null) {
                $rows[] = new AppRow(
                    $fill->line,
                    AppRowStatus::NotImported,
                    $fill->guid,
                    $summary,
                    note: self::note('import_app.reason.fuel_skipped', ['code' => (string) $fill->fuelCode]),
                    extra: $extra,
                );
                continue;
            }

            $station = $this->fillStation($fill, $stationsByFuelioId);
            $liquid = $choice->fuel->kind()->followsVolumeUnit();
            $input = [
                'filled_at' => self::dateTime($fill->date, $options->dateOrder),
                'odometer' => self::distance($fill->odometer, $options->distanceUnit),
                'fuel' => $choice->value(),
                'volume' => $liquid
                    ? self::volume($fill->volume, $options->volumeUnit)
                    : self::number($fill->volume) ?? $fill->volume,
                'price' => $liquid
                    ? self::unitPrice($fill->pricePerUnit, $options->volumeUnit)
                    : self::number($fill->pricePerUnit) ?? $fill->pricePerUnit,
                'total' => self::number($fill->total) ?? $fill->total,
                'partial' => $fill->full ? '' : '1',
                'missed_previous' => $fill->missed ? '1' : '',
                'notes' => mb_substr($fill->notes, 0, 1000),
                'station' => $station['name'],
                'station_id' => $station['id'] === null ? '' : (string) $station['id'],
            ];
            $parsed = FuelEntryForm::parse($input, $preferences, $currency);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow(
                    $fill->line,
                    AppRowStatus::Invalid,
                    $fill->guid,
                    $summary,
                    self::errors($parsed),
                    extra: $extra,
                );
                continue;
            }
            if (isset($known[$fill->guid])) {
                $rows[] = new AppRow(
                    $fill->line,
                    AppRowStatus::AlreadyImported,
                    $fill->guid,
                    $summary,
                    data: $parsed,
                    extra: $extra,
                );
                continue;
            }
            $key = DuplicateKey::of($parsed);
            if (isset($seen[$key])) {
                $rows[] = new AppRow($fill->line, AppRowStatus::Duplicate, $fill->guid, $summary, data: $parsed, extra: $extra);
                continue;
            }
            $seen[$key] = true;
            $sanity[] = $parsed;
            $figures[] = $fill->ownEconomy;
            $rows[] = new AppRow(
                $fill->line,
                AppRowStatus::Import,
                $fill->guid,
                $summary,
                data: $parsed,
                note: $station['note'],
                extra: $extra,
            );
        }

        return [$rows, $sanity, $figures];
    }

    /**
     * The station a fill-up goes to: its favourite station by Fuelio's id,
     * an existing station by name, then by position (the position is used
     * for this match only and never stored), else a new one by name.
     *
     * @param array<string, string> $byFuelioId
     * @return array{name: string, id: int|null, note: array{key: string, params: array<string, string>}|null}
     */
    private function fillStation(FuelioFill $fill, array $byFuelioId): array
    {
        $name = $fill->stationId !== '' && isset($byFuelioId[$fill->stationId])
            ? $byFuelioId[$fill->stationId]
            : mb_substr(StationName::tidy($fill->stationName()), 0, 100);
        if (!$this->stations->enabled()) {
            return ['name' => $name, 'id' => null, 'note' => null];
        }
        $existing = $this->matchStation($name, $fill->position());
        if ($existing !== null) {
            return [
                'name' => $existing->data->name,
                'id' => $existing->id,
                'note' => self::note('stations.import.links', ['name' => $existing->data->name]),
            ];
        }

        return $name === ''
            ? ['name' => '', 'id' => null, 'note' => null]
            : ['name' => $name, 'id' => null, 'note' => self::note('stations.import.new', ['name' => $name])];
    }

    /**
     * @param array{float, float}|null $position
     */
    private function matchStation(string $name, ?array $position): ?Station
    {
        if (!$this->stations->enabled()) {
            return null;
        }
        $byName = $name === '' ? null : $this->stations->existing($name);
        if ($byName !== null) {
            return $byName;
        }
        if ($position === null) {
            return null;
        }
        $near = $this->stationRows->nearby($position[0], $position[1], self::STATION_RADIUS_KM);

        return $near === [] ? null : $near[0]['station'];
    }

    /**
     * @param array<string, true> $known
     * @return list<AppRow>
     */
    private function costRows(
        FuelioExport $export,
        AppImportOptions $options,
        ?Vehicle $target,
        array $known,
        DisplayPreferences $preferences,
    ): array {
        $seen = [];
        if ($target !== null) {
            foreach ($this->maintenanceEntries->listForVehicle($target->id) as $e) {
                $seen[DuplicateKey::of($e->data)] = true;
            }
            foreach ($this->expenseEntries->listForVehicle($target->id) as $e) {
                $seen[DuplicateKey::of($e->data)] = true;
            }
        }
        $maintenanceOn = $this->features->isEnabled(Feature::Maintenance);

        $rows = [];
        foreach ($export->costs as $cost) {
            $summary = implode(' · ', array_filter([$cost->date, $cost->title, $cost->cost]));
            $extra = ['repeats' => $cost->repeats(), 'cost' => $cost];
            $skip = match (true) {
                $cost->isIncome => 'import_app.reason.income',
                $cost->isTemplate => 'import_app.reason.template',
                default => null,
            };
            $mapped = $options->categoryTarget($cost->typeId);
            $skip ??= match (true) {
                $mapped === null => 'import_app.reason.category_skipped',
                $mapped[0] === 'maintenance' && !$maintenanceOn => 'import_app.reason.maintenance_off',
                default => null,
            };
            if ($skip !== null || $mapped === null) {
                $rows[] = new AppRow(
                    $cost->line,
                    AppRowStatus::NotImported,
                    $cost->guid,
                    $summary,
                    note: self::note($skip ?? 'import_app.reason.category_skipped'),
                    extra: $extra,
                );
                continue;
            }

            $date = $options->dateOrder->normalise(explode(' ', trim($cost->date))[0]) ?? $cost->date;
            $title = trim($cost->title) !== '' ? trim($cost->title) : ($export->categories[$cost->typeId] ?? '');
            $odometer = self::number($cost->odometer);
            $parsed = $mapped[0] === 'maintenance'
                ? MaintenanceEntryForm::parse([
                    'performed_on' => $date,
                    'odometer' => $odometer === null || Decimal::compare($odometer, '0') <= 0
                        ? ''
                        : self::distance($cost->odometer, $options->distanceUnit),
                    'category' => $mapped[1]->value,
                    'title' => mb_substr($title, 0, 150),
                    'cost' => self::number($cost->cost) ?? $cost->cost,
                    'description' => $cost->notes,
                ], $preferences, [])
                : ExpenseEntryForm::parse([
                    'spent_on' => $date,
                    'category' => $mapped[1]->value,
                    'amount' => self::number($cost->cost) ?? $cost->cost,
                    'note' => mb_substr(implode(' — ', array_filter([$title, trim($cost->notes)])), 0, 200),
                ], $preferences);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow(
                    $cost->line,
                    AppRowStatus::Invalid,
                    $cost->guid,
                    $summary,
                    self::errors($parsed),
                    extra: $extra,
                );
                continue;
            }
            if (isset($known[$cost->guid])) {
                $rows[] = new AppRow(
                    $cost->line,
                    AppRowStatus::AlreadyImported,
                    $cost->guid,
                    $summary,
                    data: $parsed,
                    extra: $extra,
                );
                continue;
            }
            $key = DuplicateKey::of($parsed);
            if (isset($seen[$key])) {
                $rows[] = new AppRow($cost->line, AppRowStatus::Duplicate, $cost->guid, $summary, data: $parsed, extra: $extra);
                continue;
            }
            $seen[$key] = true;
            $rows[] = new AppRow(
                $cost->line,
                AppRowStatus::Import,
                $cost->guid,
                $summary,
                data: $parsed,
                note: self::note(
                    $mapped[0] === 'maintenance' ? 'import_app.goes_to.maintenance' : 'import_app.goes_to.expenses',
                    ['category' => $this->translator->trans($mapped[0] . '.category.' . $mapped[1]->value)],
                ),
                extra: $extra,
            );
        }

        return $rows;
    }

    /**
     * Repeating maintenance costs as schedules (the option): one per
     * category and title, every RepeatOdo or RepeatMonths of its most recent
     * importable record, whose entries are then linked to it so *last done*
     * follows them.
     *
     * @param list<AppRow> $costs
     * @return list<AppRow>
     */
    private function scheduleRows(FuelioExport $export, AppImportOptions $options, array $costs): array
    {
        $latest = [];
        foreach ($costs as $row) {
            $data = $row->data;
            $cost = $row->extra['cost'] ?? null;
            if (
                $row->status !== AppRowStatus::Import
                || !$data instanceof MaintenanceEntryData
                || !$cost instanceof FuelioCost
                || !$cost->repeats()
            ) {
                continue;
            }
            $key = self::scheduleKey($data->category, $data->title);
            if (!isset($latest[$key]) || $latest[$key][0]->performedOn < $data->performedOn) {
                $latest[$key] = [$data, $cost, $row];
            }
        }

        $rows = [];
        foreach ($latest as [$data, $cost, $row]) {
            $km = is_numeric($cost->repeatOdometer) && (float) $cost->repeatOdometer > 0.0
                ? $options->distanceUnit->toKmDecimal(self::number($cost->repeatOdometer) ?? '0', 3)
                : null;
            $schedule = new MaintenanceScheduleData(
                category: $data->category,
                title: $data->title,
                intervalKm: $km,
                intervalMonths: $cost->repeatMonths > 0 ? min($cost->repeatMonths, 240) : null,
            );
            $every = array_filter([
                $km === null ? null : $cost->repeatOdometer . ' ' . ($export->distanceText ?? $options->distanceUnit->value),
                $cost->repeatMonths > 0 ? $cost->repeatMonths . ' mo' : null,
            ]);
            $rows[] = new AppRow(
                $row->line,
                AppRowStatus::Import,
                'schedule:' . $row->sourceId,
                $data->title . ' · ' . implode(' / ', $every),
                data: $schedule,
            );
        }

        return $rows;
    }

    /**
     * Each photo against the fill-up it belongs to; only a backup ZIP
     * carries the files.
     *
     * @param list<AppRow> $fills
     * @return list<AppRow>
     */
    private function photoRows(FuelioExport $export, array $fills, ?AppArchive $archive): array
    {
        $fillByUniqueId = [];
        foreach ($fills as $row) {
            $id = $row->extra['unique_id'] ?? '';
            $fillByUniqueId[is_string($id) ? $id : ''] = $row;
        }

        $rows = [];
        foreach ($export->photos as $photo) {
            $fill = $fillByUniqueId[$photo->targetId] ?? null;
            $reason = match (true) {
                $archive === null => 'import_app.reason.photos_web',
                $photo->type !== FuelioPhoto::TYPE_FILL => 'import_app.reason.photo_type',
                !$archive->hasPhoto($photo->filename) => 'import_app.reason.photo_missing',
                $fill === null || $fill->status !== AppRowStatus::Import => 'import_app.reason.photo_no_fill',
                default => null,
            };
            $summary = $photo->filename;
            if ($reason !== null) {
                $rows[] = new AppRow($photo->line, AppRowStatus::NotImported, $photo->guid, $summary, note: self::note($reason));
                continue;
            }
            $rows[] = new AppRow(
                $photo->line,
                AppRowStatus::Import,
                $photo->guid,
                $summary,
                note: self::note('import_app.goes_to.fill', ['line' => (string) $fill->line]),
                extra: ['fill' => $fill->sourceId, 'file' => $photo->filename],
            );
        }

        return $rows;
    }

    /**
     * A new vehicle from the file's `Vehicle` row and the mapped fuels.
     */
    private function newVehicle(FuelioExport $export, AppImportOptions $options): VehicleData
    {
        $v = $export->vehicle;
        $kinds = [];
        $families = [];
        foreach ($export->fills as $fill) {
            $choice = $options->fuelTarget($fill->fuelCode);
            if ($choice !== null) {
                $kinds[$choice->fuel->kind()->value] = ($kinds[$choice->fuel->kind()->value] ?? 0) + 1;
                $families[$choice->fuel->value] = ($families[$choice->fuel->value] ?? 0) + 1;
            }
        }
        arsort($families);
        $main = Fuel::tryFrom((string) array_key_first($families)) ?? Fuel::Petrol;
        $type = match (true) {
            isset($kinds[EnergyKind::Gas->value]) => FuelType::Cng,
            isset($kinds[EnergyKind::Electric->value]) && isset($kinds[EnergyKind::Liquid->value]) => FuelType::Phev,
            $main === Fuel::Electricity => FuelType::Electric,
            $main === Fuel::Diesel => FuelType::Diesel,
            $main === Fuel::Lpg => FuelType::Lpg,
            $main === Fuel::Other => FuelType::Other,
            default => FuelType::Petrol,
        };

        $make = trim($v->make) !== '' ? trim($v->make) : trim($v->name);
        $model = trim($v->model) !== '' ? trim($v->model) : trim($v->name);
        $nickname = trim($v->name);
        $capacity = self::number($v->tank1Capacity);
        if ($capacity !== null && $type->primaryKind()->followsVolumeUnit()) {
            $capacity = Decimal::round($options->volumeUnit->toLitresDecimal($capacity, self::SI_SCALE), 1);
        }
        $vin = strtoupper(preg_replace('/\s+/', '', $v->vin) ?? '');

        return new VehicleData(
            type: VehicleType::Car,
            make: mb_substr($make === '' ? 'Fuelio' : $make, 0, 60),
            model: mb_substr($model === '' ? '?' : $model, 0, 60),
            fuelType: $type,
            nickname: $nickname === '' || mb_strtolower($nickname) === mb_strtolower(trim($make . ' ' . $model))
                ? null
                : mb_substr($nickname, 0, 60),
            year: $v->year,
            registration: trim($v->plate) === '' ? null : mb_substr(trim($v->plate), 0, 20),
            vin: $vin === '' || strlen($vin) > 17 ? null : $vin,
            capacity: $capacity,
        );
    }

    private function defaultCategory(int $id, string $name, ImportVocabulary $vocabulary): string
    {
        if (isset(self::BUILT_IN[$id])) {
            return self::BUILT_IN[$id];
        }
        $maintenance = $vocabulary->choice(MaintenanceCategory::class, 'maintenance.category.', $name);
        if ($maintenance !== null) {
            return 'maintenance:' . $maintenance;
        }
        $expense = $vocabulary->choice(ExpenseCategory::class, 'expense.category.', $name);

        return $expense !== null ? 'expense:' . $expense : 'maintenance:' . MaintenanceCategory::Other->value;
    }

    /**
     * Fuelio's codes are a family in the hundreds: 1xx is petrol (confirmed by
     * the sample). Any other code defaults to the vehicle's usual fuel.
     */
    private static function defaultFuel(int $code, ?Vehicle $target): FuelChoice
    {
        if (self::knownFuelCode($code)) {
            return new FuelChoice(Fuel::Petrol);
        }

        return new FuelChoice($target === null ? Fuel::Petrol : Fuel::defaultFor($target->data->fuelType));
    }

    private static function dateOrder(string $format): DateOrder
    {
        $format = strtolower(trim($format));

        return match (true) {
            str_starts_with($format, 'y') => DateOrder::Iso,
            str_starts_with($format, 'm') => DateOrder::MonthFirst,
            str_starts_with($format, 'd') => DateOrder::DayFirst,
            default => DateOrder::Iso,
        };
    }

    /**
     * Rows are read in km, litres and the owner's zone, as the CSV importer
     * hands them to the forms.
     */
    private static function canonical(DisplayPreferences $owner): DisplayPreferences
    {
        return new DisplayPreferences(
            $owner->locale,
            $owner->timezone,
            DistanceUnit::Kilometre,
            VolumeUnit::Litre,
            $owner->consumptionUnit,
            $owner->currency,
            $owner->theme,
        );
    }

    /**
     * A plain decimal from Fuelio's text: a point, or a comma when there is
     * no point ("49,22"); null when it isn't a number.
     */
    private static function number(string $value): ?string
    {
        $value = str_replace([' ', "\u{A0}"], '', trim($value));
        if (!str_contains($value, '.') && substr_count($value, ',') === 1) {
            $value = str_replace(',', '.', $value);
        }

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : null;
    }

    private static function distance(string $value, DistanceUnit $unit): string
    {
        $number = self::number($value);

        return $number === null ? $value : $unit->toKmDecimal($number, self::SI_SCALE);
    }

    private static function volume(string $value, VolumeUnit $unit): string
    {
        $number = self::number($value);

        return $number === null ? $value : $unit->toLitresDecimal($number, self::SI_SCALE);
    }

    private static function unitPrice(string $value, VolumeUnit $unit): string
    {
        $number = self::number($value);

        return $number === null ? $value : $unit->pricePerLitre($number, self::SI_SCALE + 3);
    }

    /**
     * "2026-09-08 16:44" in the form's shape ("2026-09-08T16:44"); a date
     * alone is noon. Anything unreadable goes through for the form's error.
     */
    private static function dateTime(string $value, DateOrder $order): string
    {
        if (preg_match('/^(\S+?)(?:[T ]+(\d{1,2}):(\d{2})(?::\d{2})?)?$/', trim($value), $m) !== 1) {
            return $value;
        }
        $date = $order->normalise($m[1]);
        if ($date === null) {
            return $value;
        }

        return isset($m[2], $m[3]) && $m[2] !== '' ? sprintf('%sT%02d:%s', $date, (int) $m[2], $m[3]) : $date . 'T12:00';
    }

    /**
     * @return list<array{field: string, key: string, params: array<string, int|string|TranslatableInterface>}>
     */
    private static function errors(ValidationErrors $errors): array
    {
        $out = [];
        foreach ($errors->all() as $field => $error) {
            $out[] = ['field' => (string) $field, 'key' => $error['key'], 'params' => $error['params']];
        }

        return $out;
    }

    /**
     * @param array<string, string> $params
     * @return array{key: string, params: array<string, string>}
     */
    private static function note(string $key, array $params = []): array
    {
        return ['key' => $key, 'params' => $params];
    }

    private static function scheduleKey(MaintenanceCategory $category, string $title): string
    {
        return $category->value . "\n" . mb_strtolower(trim($title));
    }

    private static function plate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    /**
     * @return list<string>
     */
    private static function guids(FuelioExport $export): array
    {
        return array_values(array_filter(array_merge(
            array_map(static fn (FuelioFill $f): string => $f->guid, $export->fills),
            array_map(static fn (FuelioCost $c): string => $c->guid, $export->costs),
        )));
    }
}
