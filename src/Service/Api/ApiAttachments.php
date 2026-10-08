<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerReading;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Attachment\AttachmentNotFound;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Compliance\ComplianceService;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Fuel\FuelService;
use Logbook\Service\Incident\IncidentService;
use Logbook\Service\Maintenance\MaintenanceService;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Service\Trip\TripService;
use Logbook\Service\Vehicle\PaperworkNeedsDate;
use Logbook\Service\Vehicle\VehicleNotFound;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\Serializer;
use Logbook\Support\Api\ValidationProblem;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Attachments over the API (spec.md §7.20 *Attachments*, Phase 39.3, #286,
 * #300): list, upload one file per request and delete, through the pages'
 * AttachmentService, its checks and limits. The owner entry is found as its
 * single read finds it (ApiEntries), so a trip the key's user may not see,
 * a valuation without *Can see costs* or a switched-off module is 404 here
 * too. `purchase` and `sale` are the vehicle's paperwork (the owner id is
 * the vehicle's).
 *
 * An upload runs in this order: the entry (404), EntryAccess::canChange
 * (403), an archived vehicle (409 `vehicle_archived`, except a valuation's
 * files, as 39.2's writes), a derived reading (409 `reading_derived`),
 * paperwork without its date (422), then the file (422).
 */
final readonly class ApiAttachments
{
    /** Path segment → owner type. */
    public const array OWNERS = [
        'fuel' => AttachmentOwner::Fuel,
        'odometer' => AttachmentOwner::Odometer,
        'maintenance' => AttachmentOwner::Maintenance,
        'documents' => AttachmentOwner::Compliance,
        'expenses' => AttachmentOwner::Expense,
        'valuations' => AttachmentOwner::Valuation,
        'trips' => AttachmentOwner::Trip,
        'incidents' => AttachmentOwner::Incident,
        'purchase' => AttachmentOwner::Purchase,
        'sale' => AttachmentOwner::Sale,
    ];

    /** The upload field (one file per request, #286). */
    public const string FIELD = 'file';

    public function __construct(
        private ApiEntries $entries,
        private AttachmentService $attachments,
        private EntryAccess $access,
        private VehicleAccess $vehicleAccess,
        private VehicleService $vehicles,
        private ValidationProblem $validation,
        private FuelService $fuel,
        private OdometerService $odometer,
        private MaintenanceService $maintenance,
        private ComplianceService $compliance,
        private ExpenseService $expenses,
        private TripService $trips,
        private IncidentService $incidents,
    ) {
    }

    /**
     * @param key-of<self::OWNERS> $list
     * @return list<array<string, mixed>>
     * @throws ApiProblem 404
     */
    public function list(string $list, User $user, Vehicle $vehicle, ?int $entryId): array
    {
        $owner = $this->owner($list, $user, $vehicle, $entryId);

        return array_map(
            Serializer::attachment(...),
            $this->attachments->forOwner($vehicle, self::OWNERS[$list], $owner['id']),
        );
    }

    /**
     * @param key-of<self::OWNERS> $list
     * @return array<string, mixed> the stored attachment
     * @throws ApiProblem 403, 404, 409 or 422
     */
    public function upload(
        string $list,
        User $user,
        Vehicle $vehicle,
        ?int $entryId,
        ?UploadedFileInterface $file,
        bool $tooLarge = false,
    ): array {
        $type = self::OWNERS[$list];
        $owner = $this->owner($list, $user, $vehicle, $entryId);
        if (!$this->access->canChange($user, $vehicle, $owner['createdBy'])) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may not change this entry: it is someone else\'s.');
        }
        if ($type !== AttachmentOwner::Valuation) {
            ApiWriter::assertActive($vehicle);
        }
        if ($owner['derived']) {
            throw new ApiProblem(
                409,
                'reading_derived',
                'This reading belongs to another entry; attach the file to that entry instead.',
            );
        }
        if ($type === AttachmentOwner::Purchase || $type === AttachmentOwner::Sale) {
            $refused = PaperworkNeedsDate::check(
                $vehicle->data,
                0,
                $type === AttachmentOwner::Purchase ? 1 : 0,
                0,
                $type === AttachmentOwner::Sale ? 1 : 0,
            );
            if ($refused !== null) {
                $errors = new ValidationErrors();
                $errors->add(self::FIELD, $refused->messageKey());
                throw $this->validation->of($errors);
            }
        }

        $upload = $this->checked($file, $type, $tooLarge);

        return Serializer::attachment($this->attachments->add($vehicle, $type, $owner['id'], $upload));
    }

    /**
     * An attachment named only by its id, on a vehicle the key's user may
     * view; anything else is 404 (ids reveal nothing).
     *
     * @return array{vehicle: Vehicle, attachment: Attachment}
     * @throws ApiProblem 404
     */
    public function find(User $user, int $id): array
    {
        $vehicleId = $this->attachments->vehicleIdOf($id);
        try {
            if ($vehicleId === null) {
                throw new AttachmentNotFound('No such attachment.');
            }
            $vehicle = $this->vehicles->get($user, $vehicleId);

            return ['vehicle' => $vehicle, 'attachment' => $this->attachments->get($vehicle, $id)];
        } catch (VehicleNotFound | AttachmentNotFound) {
            throw ApiProblem::notFound('There is no such attachment.');
        }
    }

    /**
     * As the page's delete link: `Log` on the vehicle and the own-entry rule
     * on who uploaded it; an archived vehicle keeps its files (409) except
     * a valuation's.
     *
     * @throws ApiProblem 403 or 409
     */
    public function delete(User $user, Vehicle $vehicle, Attachment $attachment): void
    {
        if (
            !$this->vehicleAccess->can($user, VehicleAbility::Log, $vehicle)
            || !$this->access->canChange($user, $vehicle, $attachment->uploadedBy)
        ) {
            throw new ApiProblem(403, 'forbidden', 'The key\'s user may not delete this file.');
        }
        if ($attachment->ownerType !== AttachmentOwner::Valuation) {
            ApiWriter::assertActive($vehicle);
        }
        $this->attachments->delete($vehicle, $attachment);
    }

    /**
     * The owner entry: its id, who created it, and whether it is a reading
     * another entry wrote.
     *
     * @param key-of<self::OWNERS> $list
     * @return array{id: int, createdBy: ?int, derived: bool}
     * @throws ApiProblem 404
     */
    private function owner(string $list, User $user, Vehicle $vehicle, ?int $entryId): array
    {
        if ($list === 'purchase' || $list === 'sale') {
            return ['id' => $vehicle->id, 'createdBy' => null, 'derived' => false];
        }
        $id = $entryId ?? 0;
        // The single read's rules: 404 for what the key's user may not see.
        $this->entries->read($list, $user, $vehicle, $id);
        if ($list === 'valuations') {
            return ['id' => $id, 'createdBy' => null, 'derived' => false];
        }
        $stored = match ($list) {
            'fuel' => $this->fuel->get($vehicle, $id),
            'odometer' => $this->odometer->get($vehicle, $id),
            'maintenance' => $this->maintenance->get($vehicle, $id),
            'documents' => $this->compliance->get($vehicle, $id),
            'expenses' => $this->expenses->get($vehicle, $id),
            'trips' => $this->trips->get($user, $vehicle, $id),
            'incidents' => $this->incidents->get($vehicle, $id),
        };

        return [
            'id' => $id,
            'createdBy' => $stored->createdBy,
            'derived' => $stored instanceof OdometerReading && !$stored->isManual(),
        ];
    }

    /**
     * The one file, checked as the forms check theirs (content, decode,
     * `MAX_UPLOAD_MB`).
     *
     * @throws ApiProblem 422
     */
    private function checked(?UploadedFileInterface $file, AttachmentOwner $type, bool $tooLarge): PendingUpload
    {
        $errors = new ValidationErrors();
        if ($tooLarge) {
            $errors->add(self::FIELD, 'upload.too_large', ['max' => $this->attachments->maxMegabytes()]);
            throw $this->validation->of($errors);
        }
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $errors->add(self::FIELD, 'validation.required');
            throw $this->validation->of($errors);
        }
        $upload = new PendingUpload($file, $this->attachments->check($file, $type));
        if ($upload->check->error !== null) {
            $errors->add(self::FIELD, 'upload.file.' . substr($upload->check->error, strlen('upload.')), [
                'name' => $upload->name(),
                'max' => $this->attachments->maxMegabytes(),
            ]);
            throw $this->validation->of($errors);
        }

        return $upload;
    }
}
