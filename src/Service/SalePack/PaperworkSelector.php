<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\Incident\Incident;
use Logbook\Repository\IncidentRepository;
use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Compliance\ComplianceDocument;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Maintenance\MaintenanceEntry;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Repository\ComplianceDocumentRepository;
use Logbook\Repository\MaintenanceEntryRepository;
use Logbook\Repository\OdometerReadingRepository;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Support\Date\LocalTime;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which files the sale pack's ZIP holds (spec.md §7.19).
 *
 * Every file is read by the vehicle and resolved through the entry it
 * belongs to, so a kind is what the entry is, never what the request says:
 * a document's files are offered by its type (inspection and pollution, or
 * insurance), and registration and `other` documents, sale paperwork,
 * valuations, fill-ups and expenses have no kind at all. The request only
 * picks among the kinds offered (SalePackOptions) and takes files away
 * (`exclude[]`): an id of another vehicle's file, or of a file never
 * offered, matches nothing and changes nothing.
 *
 * @phpstan-type Found array{
 *     attachment: Attachment,
 *     kind: PaperworkKind,
 *     date: DateTimeImmutable,
 *     odometerKm: ?string,
 *     entry: string,
 *     label: string,
 *     what: ?string,
 * }
 *
 * Names are in the owner's language: "2024-03-12 Service - Kwik Fit.pdf".
 * They are worked out over every file of the chosen kinds, so unticking one
 * never renames another.
 */
final readonly class PaperworkSelector
{
    /** Longest name before the extension, in characters. */
    private const int MAX_NAME = 100;

    public function __construct(
        private AttachmentRepository $attachments,
        private MaintenanceEntryRepository $maintenance,
        private ComplianceDocumentRepository $documents,
        private OdometerReadingRepository $readings,
        private FeatureToggles $features,
        private TranslatorInterface $translator,
        private IncidentRepository $incidents,
    ) {
    }

    /**
     * @return list<PaperworkKind> the kinds whose module is on
     */
    public function offered(): array
    {
        return PaperworkKind::offered($this->features->all());
    }

    public function select(User $user, Vehicle $vehicle, SalePackOptions $options): PaperworkSelection
    {
        // Incident photos are offered only while incidents are included.
        $offered = array_values(array_filter(
            $this->offered(),
            static fn (PaperworkKind $kind): bool => $kind !== PaperworkKind::IncidentPhoto || $options->incidents,
        ));
        $chosen = array_values(array_filter($offered, $options->includes(...)));
        $zone = $user->preferences->timeZone();

        $services = [];
        $documents = [];
        $readings = [];
        if (in_array(PaperworkKind::Service, $chosen, true)) {
            foreach ($this->maintenance->listForVehicle($vehicle->id) as $entry) {
                $services[$entry->id] = $entry;
            }
        }
        if (in_array(PaperworkKind::Inspection, $chosen, true) || in_array(PaperworkKind::Insurance, $chosen, true)) {
            foreach ($this->documents->listForVehicle($vehicle->id) as $document) {
                $documents[$document->id] = $document;
            }
        }
        if (in_array(PaperworkKind::Photo, $chosen, true)) {
            foreach ($this->readings->listForVehicle($vehicle->id) as $reading) {
                if ($reading->isManual()) {
                    $readings[$reading->id] = $reading;
                }
            }
        }

        $incidents = [];
        if (in_array(PaperworkKind::IncidentPhoto, $chosen, true)) {
            foreach ($this->incidents->listForVehicle($vehicle->id) as $incident) {
                $incidents[$incident->id] = $incident;
            }
        }

        $files = [];
        foreach ($this->attachments->listForVehicle($vehicle->id) as $attachment) {
            if ($attachment->vehicleId !== $vehicle->id) {
                continue;
            }
            $file = match ($attachment->ownerType) {
                AttachmentOwner::Maintenance => $this->service($attachment, $services[$attachment->ownerId] ?? null),
                AttachmentOwner::Compliance => $this->document(
                    $attachment,
                    $documents[$attachment->ownerId] ?? null,
                    $zone,
                ),
                AttachmentOwner::Odometer => $this->photo($attachment, $readings[$attachment->ownerId] ?? null, $zone),
                AttachmentOwner::Purchase => $attachment->ownerId === $vehicle->id
                    ? $this->purchase($attachment, $vehicle, $zone)
                    : null,
                // Never offered: fill-ups, expenses, the sale, valuations, trips and issues.
                AttachmentOwner::Fuel,
                AttachmentOwner::Expense,
                AttachmentOwner::Sale,
                AttachmentOwner::Valuation,
                AttachmentOwner::Trip,
                AttachmentOwner::Issue => null,
                AttachmentOwner::Incident => $this->incidentPhoto($attachment, $incidents[$attachment->ownerId] ?? null),
            };
            if ($file !== null && in_array($file['kind'], $chosen, true)) {
                $files[] = $file;
            }
        }

        usort($files, static fn (array $a, array $b): int => ($a['date'] <=> $b['date'])
            ?: ($a['attachment']->id <=> $b['attachment']->id));

        $taken = [];
        $result = [];
        foreach ($files as $file) {
            $attachment = $file['attachment'];
            $result[] = new PaperworkFile(
                attachment: $attachment,
                kind: $file['kind'],
                date: $file['date'],
                odometerKm: $file['odometerKm'],
                entry: $file['entry'],
                name: self::unique(
                    self::name($file['date'], $file['label'], $file['what']),
                    self::extension($attachment),
                    $taken,
                ),
                included: $options->keeps($attachment->id),
            );
        }

        return new PaperworkSelection($offered, $chosen, $result);
    }

    /**
     * An incident's photo: images only (a PDF there is usually a letter
     * about the claim), named by the incident's type.
     *
     * @return Found|null
     */
    private function incidentPhoto(Attachment $attachment, ?Incident $incident): ?array
    {
        if ($incident === null || !$attachment->isImage()) {
            return null;
        }
        $type = $this->translator->trans($incident->data->type->labelKey());

        return [
            'attachment' => $attachment,
            'kind' => PaperworkKind::IncidentPhoto,
            'date' => $incident->data->occurredOn,
            'odometerKm' => null,
            'entry' => $type,
            'label' => $this->translator->trans('sale_pack.source.incident_photo'),
            'what' => $type,
        ];
    }

    /**
     * @return Found|null
     */
    private function service(Attachment $attachment, ?MaintenanceEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }
        $data = $entry->data;

        return [
            'attachment' => $attachment,
            'kind' => PaperworkKind::Service,
            'date' => $data->performedOn,
            'odometerKm' => $data->odometerKm,
            'entry' => self::join([$data->title, $data->vendor]),
            'label' => $this->translator->trans('maintenance.category.' . $data->category->value),
            'what' => $data->vendor ?? $data->title,
        ];
    }

    /**
     * @return Found|null
     */
    private function document(Attachment $attachment, ?ComplianceDocument $document, DateTimeZone $zone): ?array
    {
        if ($document === null) {
            return null;
        }
        $data = $document->data;
        $kind = match ($data->type) {
            ComplianceType::Inspection, ComplianceType::Pollution => PaperworkKind::Inspection,
            ComplianceType::Insurance => PaperworkKind::Insurance,
            // Never offered: the registration document is a fraud risk, and `other` is about the owner.
            ComplianceType::Registration, ComplianceType::Other => null,
        };
        if ($kind === null) {
            return null;
        }
        $label = $this->translator->trans('sale_pack.document.' . $data->type->value);

        return [
            'attachment' => $attachment,
            'kind' => $kind,
            'date' => $data->startOn ?? LocalTime::dateOf($document->createdAt, $zone),
            'odometerKm' => $data->odometerKm,
            'entry' => self::join([$data->title ?? $label, $data->provider]),
            'label' => $label,
            'what' => $data->provider ?? $data->title,
        ];
    }

    /**
     * @return Found|null
     */
    private function photo(Attachment $attachment, ?OdometerReading $reading, DateTimeZone $zone): ?array
    {
        if ($reading === null) {
            return null;
        }
        $label = $this->translator->trans('sale_pack.source.photo');

        return [
            'attachment' => $attachment,
            'kind' => PaperworkKind::Photo,
            'date' => LocalTime::dateOf($reading->recordedAt, $zone),
            'odometerKm' => $reading->readingKm,
            'entry' => $label,
            'label' => $label,
            'what' => null,
        ];
    }

    /**
     * @return Found
     */
    private function purchase(Attachment $attachment, Vehicle $vehicle, DateTimeZone $zone): array
    {
        $label = $this->translator->trans('sale_pack.paperwork.purchase_name');

        return [
            'attachment' => $attachment,
            'kind' => PaperworkKind::Purchase,
            'date' => $vehicle->data->purchaseDate ?? LocalTime::dateOf($attachment->uploadedAt, $zone),
            'odometerKm' => null,
            'entry' => $label,
            'label' => $label,
            'what' => pathinfo($attachment->filename, PATHINFO_FILENAME),
        ];
    }

    /**
     * "2024-03-12 Service - Kwik Fit", sanitised for every file system.
     */
    public static function name(DateTimeImmutable $date, string $label, ?string $what): string
    {
        $label = self::sanitize($label);
        $what = self::sanitize($what ?? '');
        $what = mb_strtolower($what) === mb_strtolower($label) ? '' : $what;

        return self::sanitize($date->format('Y-m-d') . ' ' . self::join([$label, $what], ' - '));
    }

    /**
     * Without path separators, characters Windows refuses, control
     * characters, runs of spaces or trailing dots, and at most MAX_NAME
     * characters.
     */
    public static function sanitize(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t");

        return rtrim(mb_substr($name, 0, self::MAX_NAME), ' .');
    }

    /**
     * The parts that are not empty, joined.
     *
     * @param list<?string> $parts
     */
    private static function join(array $parts, string $glue = ', '): string
    {
        return implode($glue, array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
    }

    /**
     * The file's own extension (lower case), else one for its type.
     */
    public static function extension(Attachment $attachment): string
    {
        $extension = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1) {
            return $extension;
        }

        return match ($attachment->mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }

    /**
     * $name.$extension, or with " (2)", " (3)" … when that is taken
     * (compared without case, as most file systems do).
     *
     * @param array<string, true> $taken
     */
    public static function unique(string $name, string $extension, array &$taken): string
    {
        $candidate = $name . '.' . $extension;
        for ($n = 2; isset($taken[mb_strtolower($candidate)]); $n++) {
            $candidate = sprintf('%s (%d).%s', $name, $n, $extension);
        }
        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
