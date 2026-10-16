<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocumentData;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelChoice;
use Logbook\Domain\Fuel\FuelEntryData;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\Maintenance\MaintenanceEntryData;
use Logbook\Domain\Maintenance\MaintenanceScheduleData;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\Reminder\ManualReminderData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleType;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\ImportSourceRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Attachment\StoredFile;
use Logbook\Service\Compliance\ComplianceDocumentForm;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Fuel\FuelEntryForm;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Import\App\AppFiles;
use Logbook\Service\Import\App\AppRow;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\UnitSanity;
use Logbook\Service\Import\DuplicateKey;
use Logbook\Service\Maintenance\MaintenanceEntryForm;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Maintenance\ScheduleService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Reminder\ManualReminderForm;
use Logbook\Service\Reminder\ReminderPreferences;
use Logbook\Service\Reminder\ReminderService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\Region;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Clock\ClockInterface;
use Slim\Psr7\UploadedFile;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Imports a Tracktor export (spec.md §7.13 *The Tracktor reader*, Phase
 * 51). guess() proposes the mapping, analyse() previews every row of every
 * vehicle with its outcome, and import() writes the importable rows of
 * every vehicle in one transaction, through the same forms and services as
 * typing them in.
 */
final readonly class TracktorImporter
{
    /** Places kept when converting to km and litres; the forms round to what is stored. */
    private const int SI_SCALE = 6;
    /** Kilograms in a pound, exactly. */
    private const string KG_PER_POUND = '0.45359237';
    /** A row's three amounts may disagree by this much before it is marked. */
    private const string AMOUNT_TOLERANCE = '0.01';
    /** Tracktor's document types and the Logbook type each goes to by default. */
    private const array DOCUMENT_TYPES = [
        'insurance' => 'insurance',
        'emissions' => 'pollution',
        'roadworthiness' => 'inspection',
        'registration' => 'registration',
        'other' => 'other',
    ];

    public function __construct(
        private FuelService $fuel,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ReminderService $reminders,
        private ScheduleService $schedules,
        private OdometerService $odometer,
        private VehicleService $vehicles,
        private FuelEntryRepository $fuelEntries,
        private MaintenanceEntryRepository $maintenanceEntries,
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
     * The mapping proposed for the export.
     *
     * @param list<Vehicle> $manageable the active vehicles the user can manage
     */
    public function guess(User $user, TracktorExport $export, array $manageable): TracktorImportOptions
    {
        $vehicles = [];
        foreach ($export->vehicles as $vehicle) {
            $held = $this->holder($vehicle, $manageable);
            if ($held === null && trim($vehicle->plate) !== '') {
                foreach ($manageable as $candidate) {
                    if (self::plate($candidate->data->registration ?? '') === self::plate($vehicle->plate)) {
                        $held = $candidate;
                        break;
                    }
                }
            }
            $vehicles[$vehicle->id] = $held === null ? TracktorImportOptions::NEW_VEHICLE : (string) $held->id;
        }
        $prefs = $user->preferences;
        $documents = [];
        foreach ($export->documentTypes() as $type) {
            $documents[$type] = self::DOCUMENT_TYPES[$type] ?? ComplianceType::Other->value;
        }

        return new TracktorImportOptions(
            vehicles: $vehicles,
            distanceUnit: match ($export->config->distance) {
                'mile' => DistanceUnit::Mile,
                'kilometer' => DistanceUnit::Kilometre,
                default => $prefs->distanceUnit,
            },
            volumeUnit: match ($export->config->volume) {
                'gallon' => self::gallonFor($user),
                'liter' => VolumeUnit::Litre,
                default => $prefs->volumeUnit,
            },
            service: MaintenanceCategory::Service->value,
            documents: $documents,
        );
    }

    /**
     * Whether the guess read both units from the file (else the owner's are
     * proposed, highlighted on the mapping page).
     */
    public static function unitsFromFile(TracktorExport $export): bool
    {
        return $export->config->distance !== null && $export->config->volume !== null;
    }

    /**
     * Whether a document type is one Tracktor documents (else its default
     * is highlighted).
     */
    public static function knownDocumentType(string $type): bool
    {
        return isset(self::DOCUMENT_TYPES[$type]);
    }

    /**
     * Every row of every vehicle in the export, with what importing it
     * would do.
     *
     * @param array<string, Vehicle> $targets Tracktor vehicle id → the existing vehicle the options name
     *     (checked by the caller)
     * @param list<Vehicle> $manageable the active vehicles the user can manage, for the warning when another holds the rows
     */
    public function analyse(
        User $user,
        TracktorExport $export,
        TracktorImportOptions $options,
        array $targets,
        array $manageable,
        ?AppFiles $files = null,
    ): TracktorPreview {
        $previews = [];
        foreach ($export->vehicles as $vehicle) {
            $previews[] = $this->analyseVehicle(
                $user,
                $export,
                $vehicle,
                $options,
                $targets[$vehicle->id] ?? null,
                $manageable,
                $files,
            );
        }

        return new TracktorPreview($export, $options, $previews, $files !== null);
    }

    /**
     * Write every importable row of every vehicle in one transaction: if
     * any of it fails, nothing is written (and the files already stored are
     * deleted again).
     */
    public function import(User $user, TracktorPreview $preview, ?AppFiles $files = null): TracktorPreview
    {
        $stored = [];
        $fillIds = [];
        try {
            $written = $this->transaction->run(function () use ($user, $preview, $files, &$stored, &$fillIds): array {
                $out = [];
                foreach ($preview->vehicles as $index => $vehicle) {
                    if ($vehicle->skipped) {
                        continue;
                    }
                    $fillIds[$index] = [];
                    $out[$index] = $this->importVehicle($user, $preview->export, $vehicle, $files, $stored, $fillIds[$index]);
                }

                return $out;
            });
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                $this->files->delete($path);
            }
            throw $e;
        }

        $results = [];
        foreach ($preview->vehicles as $index => $vehicle) {
            $done = $written[$index] ?? null;
            if (!$done instanceof Vehicle) {
                $results[] = $vehicle;
                continue;
            }
            $ids = $fillIds[$index] ?? [];
            $checks = $this->fuel->checks($this->fuel->history($done));
            $results[] = $vehicle->done($done, $ids === [] ? 0 : $checks->flaggedAmong($ids));
        }

        return $preview->withVehicles($results);
    }

    /**
     * @param list<Vehicle> $manageable
     */
    private function analyseVehicle(
        User $user,
        TracktorExport $export,
        TracktorVehicle $source,
        TracktorImportOptions $options,
        ?Vehicle $target,
        array $manageable,
        ?AppFiles $files,
    ): TracktorVehiclePreview {
        $skipped = $options->skipsVehicle($source->id);
        $newVehicle = $options->createsVehicle($source->id) ? $this->newVehicle($user, $export, $source) : null;
        $currency = $target !== null
            ? $this->vehicles->currencyFor($user, $target)
            : ($newVehicle->currency ?? $user->preferences->currency);
        $known = $target === null ? [] : $this->sources->known(TracktorReader::APP, $target->id);
        $preferences = self::canonical($user->preferences);

        $warnings = [];
        $holder = $this->holder($source, $manageable);
        if (!$skipped && $holder !== null && $holder->id !== $target?->id) {
            $warnings[] = self::note('import_app.tracktor.warning.held', ['name' => $holder->name()]);
        }
        if (!$skipped && $target !== null && $export->config->currency !== null && $export->config->currency !== $currency) {
            $warnings[] = self::note('import_app.tracktor.warning.currency', [
                'file' => $export->config->currency,
                'vehicle' => $currency,
            ]);
        }
        if (!$skipped && $newVehicle !== null && $source->hasOddType()) {
            $warnings[] = self::note('import_app.tracktor.warning.type', [
                'type' => (string) $source->vehicleType,
            ]);
        }

        $sections = [];
        [$sections[TracktorVehiclePreview::FILLS], $sanityFills] = $this->fillRows(
            $export,
            $source,
            $options,
            $target,
            $known,
            $preferences,
            $currency,
        );
        $sections[TracktorVehiclePreview::SERVICES] = $this->serviceRows($source, $options, $target, $known, $preferences);
        $sections[TracktorVehiclePreview::DOCUMENTS] = $this->documentRows(
            $user,
            $source,
            $options,
            $target,
            $known,
            $preferences,
        );
        [$sections[TracktorVehiclePreview::REMINDERS], $sections[TracktorVehiclePreview::SCHEDULES]] = $this->reminderRows(
            $source,
            $options,
            $known,
            $preferences,
        );
        $sections[TracktorVehiclePreview::READINGS] = $this->readingRows($export, $source, $options, $target, $known, $sections);
        $sections[TracktorVehiclePreview::FILES] = $this->fileRows($source, $target, $newVehicle, $sections, $files);
        $sections[TracktorVehiclePreview::DETAILS] = self::detailRows($source);

        return new TracktorVehiclePreview(
            source: $source,
            target: $target,
            newVehicle: $newVehicle,
            skipped: $skipped,
            currency: $currency,
            sections: $sections,
            sanity: UnitSanity::of($sanityFills, array_fill(0, count($sanityFills), ''), null),
            warnings: $warnings,
        );
    }

    /**
     * @param list<string> $stored paths of the files written so far
     * @param list<int> $fillIds the fill-ups written
     */
    private function importVehicle(
        User $user,
        TracktorExport $export,
        TracktorVehiclePreview $preview,
        ?AppFiles $files,
        array &$stored,
        array &$fillIds,
    ): Vehicle {
        $now = $this->clock->now();
        $zone = $user->preferences->timeZone();
        $vehicle = $preview->target;
        $known = [];
        if ($vehicle === null) {
            $data = $preview->newVehicle ?? throw new \LogicException('No vehicle to import into.');
            $vehicle = $this->vehicles->create($user, $data);
        } else {
            $known = $this->sources->known(TracktorReader::APP, $vehicle->id);
        }
        $record = fn (string $sourceId, string $type, int $id) => $this->sources->record(
            TracktorReader::APP,
            $sourceId,
            $vehicle->id,
            $type,
            $id,
            $user->id,
            $now,
        );
        if (!isset($known[$preview->source->id])) {
            $record($preview->source->id, 'vehicle', $vehicle->id);
        }

        // The files each row carries, by its source id.
        $filesOf = [];
        foreach ($preview->withStatus(TracktorVehiclePreview::FILES, AppRowStatus::Import) as $row) {
            $owner = $row->extra['owner'] ?? null;
            $file = $row->extra['file'] ?? null;
            if (is_string($owner) && is_string($file)) {
                $filesOf[$owner][] = $file;
            }
        }
        $attach = function (AttachmentOwner $type, string $sourceId, int $id) use ($vehicle, $files, $filesOf, &$stored): void {
            if ($files !== null && isset($filesOf[$sourceId])) {
                $this->attach($vehicle, $type, $id, $filesOf[$sourceId], $files, $stored);
            }
        };

        foreach ($preview->withStatus(TracktorVehiclePreview::SCHEDULES, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof MaintenanceScheduleData);
            $record($row->sourceId, 'schedule', $this->schedules->create($vehicle, $data)->id);
        }
        foreach ($preview->withStatus(TracktorVehiclePreview::SERVICES, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof MaintenanceEntryData);
            $id = $this->maintenance->create($vehicle, $data, $zone)->id;
            $record($row->sourceId, 'maintenance', $id);
            $attach(AttachmentOwner::Maintenance, $row->sourceId, $id);
        }
        foreach ($preview->withStatus(TracktorVehiclePreview::FILLS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof FuelEntryData);
            $id = $this->fuel->create($vehicle, $data)->id;
            $fillIds[] = $id;
            $record($row->sourceId, 'fuel', $id);
            $attach(AttachmentOwner::Fuel, $row->sourceId, $id);
        }
        foreach ($preview->withStatus(TracktorVehiclePreview::DOCUMENTS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof ComplianceDocumentData);
            $id = $this->compliance->create($vehicle, $data, $zone)->id;
            $record($row->sourceId, 'document', $id);
            $attach(AttachmentOwner::Compliance, $row->sourceId, $id);
        }
        foreach ($preview->withStatus(TracktorVehiclePreview::REMINDERS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof ManualReminderData);
            $reminder = $this->reminders->createManual($user, new ManualReminderData(
                $vehicle->id,
                $data->title,
                $data->dueOn,
                $data->leadTimeDays,
                $data->notes,
                $data->dueKm,
            ));
            if (($row->extra['completed'] ?? false) === true) {
                $this->reminders->markDone($reminder);
            }
            $record($row->sourceId, 'reminder', $reminder->id);
        }
        foreach ($preview->withStatus(TracktorVehiclePreview::READINGS, AppRowStatus::Import) as $row) {
            $data = $row->data;
            assert($data instanceof OdometerReadingData);
            $record($row->sourceId, 'reading', $this->odometer->create($vehicle, $data)->id);
        }

        $photo = null;
        foreach ($preview->withStatus(TracktorVehiclePreview::FILES, AppRowStatus::Import) as $row) {
            if (($row->extra['owner'] ?? null) === $preview->source->id && is_string($row->extra['file'] ?? null)) {
                $photo = $row->extra['file'];
            }
        }
        if ($photo !== null && $files !== null) {
            $this->setPhoto($user, $vehicle, $photo, $files, $stored);
        }

        return $vehicle;
    }

    /**
     * Store a row's files as attachments, checked and stripped as any
     * upload is (spec.md §7.12). A file that is missing or refused is
     * skipped; the row stays.
     *
     * @param list<string> $names
     * @param list<string> $stored
     */
    private function attach(Vehicle $vehicle, AttachmentOwner $type, int $id, array $names, AppFiles $files, array &$stored): void
    {
        $pending = [];
        foreach ($names as $name) {
            $path = $files->extract($name);
            if ($path === null) {
                continue;
            }
            $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');
            $file = new UploadedFile($path, self::originalName($name), $mime, (int) filesize($path), UPLOAD_ERR_OK);
            $check = $this->attachments->check($file, $type);
            if ($check->isValid()) {
                $pending[] = new PendingUpload($file, $check);
            }
        }
        foreach (array_chunk($pending, max(1, $this->attachments->maxFiles())) as $chunk) {
            $this->attachments->saveWithFiles(
                new PendingUploads($chunk),
                /** @param list<StoredFile> $saved */
                function (array $saved) use ($vehicle, $type, $id, &$stored): void {
                    foreach ($saved as $file) {
                        $stored[] = $file->path;
                    }
                    $this->attachments->record($vehicle, $type, $id, $saved);
                },
            );
        }
    }

    /**
     * @param list<string> $stored
     */
    private function setPhoto(User $user, Vehicle $vehicle, string $name, AppFiles $files, array &$stored): void
    {
        $path = $files->extract($name);
        if ($path === null) {
            return;
        }
        $mime = (string) (mime_content_type($path) ?: 'application/octet-stream');
        $file = new UploadedFile($path, self::originalName($name), $mime, (int) filesize($path), UPLOAD_ERR_OK);
        $checked = FileUpload::check($file, $this->vehicles->maxPhotoBytes(), UploadKind::Image);
        if (!$checked->isValid()) {
            return;
        }
        $this->vehicles->replacePhoto($user, $vehicle, $file, $checked);
        $photo = $this->vehicles->get($user, $vehicle->id)->photoPath;
        if ($photo !== null) {
            $stored[] = $photo;
        }
    }

    /**
     * @param array<string, true> $known
     * @return array{0: list<AppRow>, 1: list<FuelEntryData>} the rows, and the fill-ups for the sanity line
     */
    private function fillRows(
        TracktorExport $export,
        TracktorVehicle $source,
        TracktorImportOptions $options,
        ?Vehicle $target,
        array $known,
        DisplayPreferences $preferences,
        string $currency,
    ): array {
        $seen = $target === null ? [] : array_fill_keys(array_map(
            static fn ($e): string => DuplicateKey::of($e->data),
            $this->fuelEntries->listForVehicle($target->id),
        ), true);
        $fuel = self::fuel($source->fuelType);
        $rows = [];
        $sanity = [];

        foreach ($source->fills as $fill) {
            $summary = self::summary([$fill->date, $fill->odometer, $fill->amount, $fill->cost]);
            [$volume, $price, $unitReason] = $this->amounts($export, $fuel, $fill, $options);
            if ($unitReason !== null) {
                $rows[] = new AppRow($fill->line, AppRowStatus::NotImported, $fill->id, $summary, note: $unitReason);
                continue;
            }
            $input = [
                'filled_at' => $fill->date === null ? '' : $fill->date . 'T12:00',
                'odometer' => $fill->odometer === null
                    ? ''
                    : $options->distanceUnit->toKmDecimal($fill->odometer, self::SI_SCALE),
                'fuel' => (new FuelChoice($fuel))->value(),
                'volume' => $volume ?? '',
                'price' => $price ?? '',
                'total' => $fill->cost ?? '',
                'partial' => $fill->full ? '' : '1',
                'missed_previous' => $fill->missedLast ? '1' : '',
                'notes' => mb_substr($fill->notes, 0, 1000),
            ];
            $parsed = FuelEntryForm::parse($input, $preferences, $currency);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow($fill->line, AppRowStatus::Invalid, $fill->id, $summary, self::errors($parsed));
                continue;
            }
            if (isset($known[$fill->id])) {
                $rows[] = new AppRow($fill->line, AppRowStatus::AlreadyImported, $fill->id, $summary, data: $parsed);
                continue;
            }
            $key = DuplicateKey::of($parsed);
            if (isset($seen[$key])) {
                $rows[] = new AppRow($fill->line, AppRowStatus::Duplicate, $fill->id, $summary, data: $parsed);
                continue;
            }
            $seen[$key] = true;
            $sanity[] = $parsed;
            $rows[] = new AppRow(
                $fill->line,
                AppRowStatus::Import,
                $fill->id,
                $summary,
                data: $parsed,
                note: self::amountsDisagree($fill) ? self::note('import_app.tracktor.amounts_differ') : null,
            );
        }

        return [$rows, $sanity];
    }

    /**
     * The volume and price per unit in litres (kWh, kg) as the form takes
     * them, or why this fill-up's unit can't come across (#471).
     *
     * @return array{0: string|null, 1: string|null, 2: array{key: string, params: array<string, string>}|null}
     */
    private function amounts(TracktorExport $export, Fuel $fuel, TracktorFill $fill, TracktorImportOptions $options): array
    {
        // kWh, and CNG in kilograms, come across as they are. Without its
        // own unit, LPG or CNG is in the volume unit, as Tracktor shows it.
        $unit = match ($fuel) {
            Fuel::Electricity => 'kilogram',
            Fuel::Lpg => $export->config->lpg ?? $export->config->volume ?? 'liter',
            Fuel::Cng => $export->config->cng ?? $export->config->volume ?? 'kilogram',
            default => 'liter',
        };
        $asIs = $fuel === Fuel::Electricity || ($fuel === Fuel::Cng && $unit === 'kilogram');
        if ($asIs) {
            return [$fill->amount, $fill->rate, null];
        }
        if ($fuel === Fuel::Cng && $unit === 'pound') {
            return [
                $fill->amount === null ? null : Decimal::multiply($fill->amount, self::KG_PER_POUND, self::SI_SCALE),
                $fill->rate === null ? null : Decimal::divide($fill->rate, self::KG_PER_POUND, self::SI_SCALE + 3),
                null,
            ];
        }
        // LPG by weight, or CNG by volume: Logbook records neither (#471).
        if ($fuel === Fuel::Cng || !in_array($unit, ['liter', 'gallon'], true)) {
            return [null, null, self::note('import_app.tracktor.reason.unit', [
                'fuel' => $this->translator->trans('fuel.fuel.' . $fuel->value),
                'unit' => $this->translator->trans('import_app.tracktor.unit.' . $unit),
            ])];
        }
        // Petrol and diesel follow the page's volume unit, as Fuelio's do;
        // LPG its own (`liter`, or a gallon as the page chose it).
        $volumeUnit = match (true) {
            $fuel !== Fuel::Lpg => $options->volumeUnit,
            $unit === 'liter' => VolumeUnit::Litre,
            $options->volumeUnit !== VolumeUnit::Litre => $options->volumeUnit,
            default => VolumeUnit::UsGallon,
        };

        return [
            $fill->amount === null ? null : $volumeUnit->toLitresDecimal($fill->amount, self::SI_SCALE),
            $fill->rate === null ? null : $volumeUnit->pricePerLitre($fill->rate, self::SI_SCALE + 3),
            null,
        ];
    }

    /**
     * @param array<string, true> $known
     * @return list<AppRow>
     */
    private function serviceRows(
        TracktorVehicle $source,
        TracktorImportOptions $options,
        ?Vehicle $target,
        array $known,
        DisplayPreferences $preferences,
    ): array {
        $seen = [];
        if ($target !== null) {
            foreach ($this->maintenanceEntries->listForVehicle($target->id) as $e) {
                $seen[DuplicateKey::of($e->data)] = true;
            }
        }
        $category = $options->serviceCategory();
        $skip = $this->features->isEnabled(Feature::Maintenance)
            ? 'import_app.tracktor.reason.service_skipped'
            : 'import_app.reason.maintenance_off';
        if (!$this->features->isEnabled(Feature::Maintenance)) {
            $category = null;
        }

        $rows = [];
        foreach ($source->services as $log) {
            $summary = self::summary([$log->date, $log->serviceCenter, $log->cost]);
            if ($category === null) {
                $rows[] = new AppRow($log->line, AppRowStatus::NotImported, $log->id, $summary, note: self::note($skip));
                continue;
            }
            $lines = preg_split('/\R/', trim($log->notes)) ?: [];
            $title = trim((string) array_shift($lines));
            $parsed = MaintenanceEntryForm::parse([
                'performed_on' => $log->date ?? '',
                'odometer' => $log->odometer === null ? '' : $options->distanceUnit->toKmDecimal($log->odometer, self::SI_SCALE),
                'category' => $category->value,
                'title' => mb_substr($title !== '' ? $title : $this->serviceTitle(), 0, MaintenanceEntryForm::TITLE_MAX),
                'cost' => $log->cost ?? '0',
                'vendor' => mb_substr($log->serviceCenter, 0, 100),
                'description' => trim(implode("\n", $lines)),
            ], $preferences, []);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow($log->line, AppRowStatus::Invalid, $log->id, $summary, self::errors($parsed));
                continue;
            }
            if (isset($known[$log->id])) {
                $rows[] = new AppRow($log->line, AppRowStatus::AlreadyImported, $log->id, $summary, data: $parsed);
                continue;
            }
            $key = DuplicateKey::of($parsed);
            if (isset($seen[$key])) {
                $rows[] = new AppRow($log->line, AppRowStatus::Duplicate, $log->id, $summary, data: $parsed);
                continue;
            }
            $seen[$key] = true;
            $rows[] = new AppRow(
                $log->line,
                AppRowStatus::Import,
                $log->id,
                $summary,
                data: $parsed,
                note: self::note('import_app.goes_to.maintenance', [
                    'category' => $this->translator->trans('maintenance.category.' . $category->value),
                ]),
            );
        }

        return $rows;
    }

    /**
     * @param array<string, true> $known
     * @return list<AppRow>
     */
    private function documentRows(
        User $user,
        TracktorVehicle $source,
        TracktorImportOptions $options,
        ?Vehicle $target,
        array $known,
        DisplayPreferences $preferences,
    ): array {
        $seen = [];
        if ($target !== null) {
            foreach ($this->compliance->list($target) as $document) {
                $seen[DuplicateKey::of($document->data)] = true;
            }
        }
        $enabled = $this->features->isEnabled(Feature::Compliance);
        $today = $this->clock->now()->setTimezone($user->preferences->timeZone())->format('Y-m-d');

        $rows = [];
        foreach ($source->documents as $document) {
            $label = self::knownDocumentType($document->type)
                ? $this->translator->trans('import_app.tracktor.document_type.' . $document->type)
                : $document->type;
            $summary = self::summary([$label, $document->number, $document->startDate]);
            $type = $options->documentType($document->type);
            if (!$enabled || $type === null) {
                $rows[] = new AppRow($document->line, AppRowStatus::NotImported, $document->id, $summary, note: self::note(
                    $enabled ? 'import_app.tracktor.reason.document_skipped' : 'import_app.tracktor.reason.compliance_off',
                ));
                continue;
            }
            $expiry = $document->endDate;
            $note = null;
            if ($options->schedules && $expiry === null && $document->recurs() && $document->startDate !== null) {
                $months = $document->recurrence === 'yearly' ? 12 * $document->interval : $document->interval;
                $expiry = self::nextDue($document->startDate, $months, $today);
                $note = self::note('import_app.tracktor.next_due', ['date' => $expiry]);
            } elseif ($document->recurs()) {
                $note = self::note('import_app.tracktor.recurs');
            }
            $parsed = ComplianceDocumentForm::parse([
                'type' => $type->value,
                'title' => $document->type === 'other' ? $document->otherLabel : '',
                'provider' => mb_substr($document->issuer, 0, 100),
                'reference' => mb_substr($document->number, 0, 100),
                'start_on' => $document->startDate ?? '',
                'expiry_on' => $expiry ?? '',
                'cost' => $document->cost ?? '0',
                'notes' => $document->notes,
            ], $preferences);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow($document->line, AppRowStatus::Invalid, $document->id, $summary, self::errors($parsed));
                continue;
            }
            if (isset($known[$document->id])) {
                $rows[] = new AppRow($document->line, AppRowStatus::AlreadyImported, $document->id, $summary, data: $parsed);
                continue;
            }
            $key = DuplicateKey::of($parsed);
            if (isset($seen[$key])) {
                $rows[] = new AppRow($document->line, AppRowStatus::Duplicate, $document->id, $summary, data: $parsed);
                continue;
            }
            $seen[$key] = true;
            $rows[] = new AppRow(
                $document->line,
                AppRowStatus::Import,
                $document->id,
                $summary,
                data: $parsed,
                note: $note ?? self::note('import_app.tracktor.goes_to.document', [
                    'type' => $this->translator->trans('compliance.type.' . $type->value),
                ]),
            );
        }

        return $rows;
    }

    /**
     * Reminders as manual reminders, completed ones marked done (#466); with
     * *Make schedules*, an open maintenance reminder recurring monthly or
     * yearly becomes a service schedule instead.
     *
     * @param array<string, true> $known
     * @return array{0: list<AppRow>, 1: list<AppRow>} the reminders and the schedules
     */
    private function reminderRows(
        TracktorVehicle $source,
        TracktorImportOptions $options,
        array $known,
        DisplayPreferences $preferences,
    ): array {
        $remindersOn = $this->features->isEnabled(Feature::Reminders);
        $maintenanceOn = $this->features->isEnabled(Feature::Maintenance);
        $rows = [];
        $schedules = [];
        foreach ($source->reminders as $reminder) {
            $type = in_array($reminder->type, TracktorReminder::TYPES, true) ? $reminder->type : 'custom';
            $label = $this->translator->trans('import_app.tracktor.reminder_type.' . $type);
            $title = $reminder->note === '' ? $label : $label . ': ' . $reminder->note;
            $title = mb_substr($title, 0, ManualReminderForm::TITLE_MAX);
            $summary = self::summary([$reminder->dueDate, $title]);

            $months = match ($reminder->recurrence) {
                'yearly' => 12 * $reminder->interval,
                'monthly' => $reminder->interval,
                default => null,
            };
            if ($options->schedules && $type === 'maintenance' && !$reminder->completed && $months !== null && $maintenanceOn) {
                $category = $options->serviceCategory() ?? MaintenanceCategory::Service;
                $scheduleTitle = $reminder->note !== '' ? $reminder->note : $this->serviceTitle();
                $schedules[] = new AppRow(
                    $reminder->line,
                    isset($known[$reminder->id]) ? AppRowStatus::AlreadyImported : AppRowStatus::Import,
                    $reminder->id,
                    $scheduleTitle,
                    data: new MaintenanceScheduleData(
                        category: $category,
                        title: mb_substr($scheduleTitle, 0, MaintenanceEntryForm::TITLE_MAX),
                        intervalMonths: min($months, 240),
                    ),
                    note: self::note('import_app.tracktor.every_months', ['months' => (string) $months]),
                );
                $rows[] = new AppRow(
                    $reminder->line,
                    AppRowStatus::NotImported,
                    $reminder->id,
                    $summary,
                    note: self::note('import_app.tracktor.reason.became_schedule'),
                );
                continue;
            }
            $skip = match (true) {
                !$remindersOn => 'import_app.tracktor.reason.reminders_off',
                !$options->reminders => 'import_app.tracktor.reason.reminders_skipped',
                default => null,
            };
            if ($skip !== null) {
                $rows[] = new AppRow(
                    $reminder->line,
                    AppRowStatus::NotImported,
                    $reminder->id,
                    $summary,
                    note: self::note($skip),
                );
                continue;
            }
            $parsed = ManualReminderForm::parse([
                'vehicle_id' => '0',
                'title' => $title,
                'due_on' => $reminder->dueDate ?? '',
                'lead_time_days' => (string) min($reminder->leadDays(), ReminderPreferences::MAX_DAYS),
            ], $preferences, [0]);
            if ($parsed instanceof ValidationErrors) {
                $rows[] = new AppRow($reminder->line, AppRowStatus::Invalid, $reminder->id, $summary, self::errors($parsed));
                continue;
            }
            $rows[] = new AppRow(
                $reminder->line,
                isset($known[$reminder->id]) ? AppRowStatus::AlreadyImported : AppRowStatus::Import,
                $reminder->id,
                $summary,
                data: $parsed,
                note: $reminder->completed ? self::note('import_app.tracktor.marked_done') : null,
                extra: ['completed' => $reminder->completed],
            );
        }

        return [$rows, $schedules];
    }

    /**
     * The vehicle's own odometer as a reading dated the export, only when
     * higher than every imported reading and the target's latest.
     *
     * @param array<string, true> $known
     * @param array<string, list<AppRow>> $sections
     * @return list<AppRow>
     */
    private function readingRows(
        TracktorExport $export,
        TracktorVehicle $source,
        TracktorImportOptions $options,
        ?Vehicle $target,
        array $known,
        array $sections,
    ): array {
        if ($source->odometer === null || Decimal::compare($source->odometer, '0') <= 0) {
            return [];
        }
        $km = $options->distanceUnit->toKmDecimal($source->odometer, 1);
        $sourceId = sprintf('odometer:%s:%s', $source->id, $source->odometer);
        $summary = $source->odometer;
        $highest = '0';
        foreach ([TracktorVehiclePreview::FILLS, TracktorVehiclePreview::SERVICES] as $section) {
            foreach ($sections[$section] ?? [] as $row) {
                $reading = $row->data instanceof FuelEntryData ? $row->data->odometerKm
                    : ($row->data instanceof MaintenanceEntryData ? $row->data->odometerKm : null);
                if ($reading !== null && Decimal::compare($reading, $highest) > 0) {
                    $highest = $reading;
                }
            }
        }
        $latest = $target === null ? null : $this->odometer->history($target)->latest();
        if ($latest !== null && Decimal::compare($latest->readingKm, $highest) > 0) {
            $highest = $latest->readingKm;
        }
        if (isset($known[$sourceId])) {
            return [new AppRow($source->line, AppRowStatus::AlreadyImported, $sourceId, $summary)];
        }
        if (Decimal::compare($km, $highest) <= 0) {
            return [new AppRow(
                $source->line,
                AppRowStatus::NotImported,
                $sourceId,
                $summary,
                note: self::note('import_app.tracktor.reason.reading_lower'),
            )];
        }

        return [new AppRow(
            $source->line,
            AppRowStatus::Import,
            $sourceId,
            $summary,
            data: new OdometerReadingData($km, $export->exportedAt ?? $this->clock->now()),
            note: self::note('import_app.tracktor.reading'),
        )];
    }

    /**
     * Each file a row or the photo points at, with whether it comes across.
     *
     * @param array<string, list<AppRow>> $sections
     * @return list<AppRow>
     */
    private function fileRows(
        TracktorVehicle $source,
        ?Vehicle $target,
        ?VehicleData $newVehicle,
        array $sections,
        ?AppFiles $files,
    ): array {
        $owners = [];
        if ($source->image !== null) {
            $photoKept = $newVehicle === null && ($target === null || $target->photoPath !== null);
            $owners[] = [$source->line, $source->id, $source->image, $photoKept ? 'import_app.tracktor.reason.photo_kept' : null];
        }
        $rowsById = [];
        $owning = [TracktorVehiclePreview::FILLS, TracktorVehiclePreview::SERVICES, TracktorVehiclePreview::DOCUMENTS];
        foreach ($owning as $section) {
            foreach ($sections[$section] ?? [] as $row) {
                $rowsById[$row->sourceId] = $row;
            }
        }
        foreach ([...$source->fills, ...$source->services, ...$source->documents] as $entry) {
            if ($entry->attachment === null) {
                continue;
            }
            $row = $rowsById[$entry->id] ?? null;
            $owners[] = [
                $entry->line,
                $entry->id,
                $entry->attachment,
                $row === null || $row->status !== AppRowStatus::Import ? 'import_app.tracktor.reason.file_row' : null,
            ];
        }

        $rows = [];
        foreach ($owners as [$line, $owner, $file, $reason]) {
            $reason ??= match (true) {
                $files === null => 'import_app.tracktor.reason.files_not_given',
                !$files->has($file) => 'import_app.tracktor.reason.file_missing',
                default => null,
            };
            $rows[] = new AppRow(
                $line,
                $reason === null ? AppRowStatus::Import : AppRowStatus::NotImported,
                'file:' . $owner . ':' . $file,
                self::originalName($file),
                note: $reason === null ? null : self::note($reason),
                extra: ['owner' => $owner, 'file' => $file],
            );
        }

        return $rows;
    }

    /**
     * The vehicle's colour and custom fields, which have nowhere to go
     * (#470).
     *
     * @return list<AppRow>
     */
    private static function detailRows(TracktorVehicle $source): array
    {
        $details = [];
        if ($source->color !== '') {
            $details['color'] = $source->color;
        }
        foreach ($source->customFields as $name => $value) {
            $details[$name] = $value;
        }
        $rows = [];
        foreach ($details as $name => $value) {
            $rows[] = new AppRow(
                $source->line,
                AppRowStatus::NotImported,
                'detail:' . $source->id . ':' . $name,
                ($name === 'color' ? '#' : $name . ': ') . ltrim($value, $name === 'color' ? '#' : ''),
                note: self::note('import_app.tracktor.reason.no_notes'),
            );
        }

        return $rows;
    }

    /**
     * A new vehicle from Tracktor's vehicle row.
     */
    private function newVehicle(User $user, TracktorExport $export, TracktorVehicle $source): VehicleData
    {
        $vin = strtoupper(preg_replace('/\s+/', '', $source->vin) ?? '');
        $currency = $export->config->currency;

        return new VehicleData(
            type: $source->isBike() ? VehicleType::Bike : VehicleType::Car,
            make: mb_substr($source->make === '' ? 'Tracktor' : $source->make, 0, 60),
            model: mb_substr($source->model === '' ? '?' : $source->model, 0, 60),
            fuelType: match ($source->fuelType) {
                'diesel' => FuelType::Diesel,
                'electric' => FuelType::Electric,
                'lpg' => FuelType::Lpg,
                'cng' => FuelType::Cng,
                default => FuelType::Petrol,
            },
            year: $source->year,
            registration: trim($source->plate) === '' ? null : mb_substr(trim($source->plate), 0, 20),
            vin: $vin === '' || strlen($vin) > 17 || preg_match('/^[A-Z0-9]+$/', $vin) !== 1 ? null : $vin,
            currency: $currency !== null && $currency !== $user->preferences->currency ? $currency : null,
        );
    }

    /**
     * The vehicle that already holds this Tracktor vehicle's id or its rows'
     * ids, among those the user can manage (#469).
     *
     * @param list<Vehicle> $manageable
     */
    private function holder(TracktorVehicle $source, array $manageable): ?Vehicle
    {
        $id = $this->sources->vehicleHolding(
            TracktorReader::APP,
            $source->ids(),
            array_map(static fn (Vehicle $v): int => $v->id, $manageable),
        );
        foreach ($manageable as $candidate) {
            if ($candidate->id === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The gallon to propose (#464): the owner's when they use one, else the
     * UK gallon for an owner in the United Kingdom or Ireland, else US.
     */
    private static function gallonFor(User $user): VolumeUnit
    {
        $prefs = $user->preferences;
        if ($prefs->volumeUnit !== VolumeUnit::Litre) {
            return $prefs->volumeUnit;
        }

        return in_array(Region::of($prefs->locale), ['GB', 'IE'], true) || $prefs->currency === 'GBP'
            ? VolumeUnit::UkGallon
            : VolumeUnit::UsGallon;
    }

    private static function fuel(string $fuelType): Fuel
    {
        return match ($fuelType) {
            'diesel' => Fuel::Diesel,
            'electric' => Fuel::Electricity,
            'lpg' => Fuel::Lpg,
            'cng' => Fuel::Cng,
            default => Fuel::Petrol,
        };
    }

    /**
     * All three amounts given and amount × rate more than a cent from cost.
     */
    private static function amountsDisagree(TracktorFill $fill): bool
    {
        if ($fill->amount === null || $fill->rate === null || $fill->cost === null) {
            return false;
        }
        $product = Decimal::multiply($fill->amount, $fill->rate, 6);
        $difference = Decimal::subtract($product, $fill->cost);
        if (Decimal::compare($difference, '0') < 0) {
            $difference = Decimal::subtract('0', $difference);
        }

        return Decimal::compare($difference, self::AMOUNT_TOLERANCE) > 0;
    }

    /**
     * The first anniversary of $start, every $months, after $today: the
     * next due date Tracktor shows for a recurring document.
     */
    private static function nextDue(string $start, int $months, string $today): string
    {
        $utc = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($start, $utc);
        $limit = new DateTimeImmutable($today, $utc);
        $step = max(1, $months);
        $n = 1;
        do {
            $next = $date->modify(sprintf('+%d months', $step * $n));
            $n++;
        } while ($next <= $limit && $n < 1200);

        return $next->format('Y-m-d');
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
     * Tracktor stores a file as `<epoch ms>-<original name>`; the original
     * name is what the owner knows it by.
     */
    private static function originalName(string $file): string
    {
        return preg_match('/^\d{10,}-(.+)$/', $file, $m) === 1 ? $m[1] : $file;
    }

    private function serviceTitle(): string
    {
        return $this->translator->trans('import_app.tracktor.service_title');
    }

    /**
     * A row at a glance: its non-empty parts.
     *
     * @param list<string|null> $parts
     */
    private static function summary(array $parts): string
    {
        return implode(' · ', array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
    }

    private static function plate(string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $plate));
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
}
