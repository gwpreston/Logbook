<?php

declare(strict_types=1);

namespace Logbook\Service\Vehicle;

use Collator;
use InvalidArgumentException;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Odometer\OdometerReadingData;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Domain\Vehicle\VehicleData;
use Logbook\Domain\Vehicle\VehicleStatus;
use Logbook\Repository\TyreRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Attachment\StoredFile;
use Logbook\Service\Odometer\OdometerService;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Money\Currency;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The garage: vehicles of one owner, their photos, purchase and sale
 * paperwork and archive state (spec.md §7.1).
 *
 * "Fleet scope" for later phases' totals is listFleet(): active vehicles
 * only, unless archived ones are explicitly included.
 */
final readonly class VehicleService
{
    private const string PHOTO_DIRECTORY = 'vehicles';

    public function __construct(
        private VehicleRepository $vehicles,
        private FileStorage $files,
        private AttachmentService $attachments,
        private ClockInterface $clock,
        private AppSettings $settings,
        private OdometerService $odometer,
        private TyreRepository $tyres,
    ) {
    }

    /**
     * Vehicles sorted for display: active first, then by name in the
     * owner's language.
     *
     * @return list<Vehicle>
     */
    public function listFleet(User $user, bool $includeArchived = false): array
    {
        $vehicles = $this->vehicles->listForUser($user->id, $includeArchived);
        $collator = new Collator($user->preferences->locale);

        usort($vehicles, static fn (Vehicle $a, Vehicle $b): int => ($a->isArchived() <=> $b->isArchived())
            ?: ((int) $collator->compare($a->name(), $b->name()) ?: $a->id <=> $b->id));

        return $vehicles;
    }

    /**
     * @return array{active: int, archived: int}
     */
    public function counts(User $user): array
    {
        return [
            'active' => $this->vehicles->countByStatus($user->id, VehicleStatus::Active),
            'archived' => $this->vehicles->countByStatus($user->id, VehicleStatus::Archived),
        ];
    }

    /**
     * @throws VehicleNotFound
     */
    public function get(User $user, int $id): Vehicle
    {
        return $this->vehicles->find($user->id, $id) ?? throw new VehicleNotFound(sprintf('Vehicle %d not found.', $id));
    }

    /**
     * Add a vehicle; with a starting reading, also its first manual reading
     * (at now when it was read today, else at local noon on its date,
     * StartingReading), through the odometer service like any other
     * reading; with purchase or sale paperwork, those files. The files are
     * written first, then the vehicle, the reading and the files' rows in
     * one transaction, so a failure leaves nothing behind (spec.md §7.1,
     * §7.12).
     *
     * @throws PaperworkNeedsDate when paperwork is given without its date
     */
    public function create(
        User $user,
        VehicleData $data,
        ?StartingReading $starting = null,
        OwnershipFiles $files = new OwnershipFiles(),
    ): Vehicle {
        $refusal = PaperworkNeedsDate::check($data, 0, count($files->purchase), 0, count($files->sale));
        if ($refusal !== null) {
            throw $refusal;
        }

        return $this->attachments->saveWithFiles($files->all(), function (array $stored) use (
            $user,
            $data,
            $starting,
            $files,
        ): Vehicle {
            $now = $this->clock->now();
            $vehicle = $this->get($user, $this->vehicles->insert($user->id, $data, $now));
            if ($starting !== null) {
                $at = $starting->recordedAt($now, $user->preferences->timeZone());
                $this->odometer->create($vehicle, new OdometerReadingData($starting->km, $at));
            }
            $this->recordPaperwork($vehicle, $files, $stored);

            return $vehicle;
        });
    }

    /**
     * Save the vehicle's details with any new purchase or sale paperwork,
     * all or nothing, as create() does.
     *
     * @throws TyresBlockTypeChange when the new type lacks a position a tyre is fitted at
     * @throws PaperworkNeedsDate when paperwork would be left without its date
     */
    public function update(User $user, Vehicle $vehicle, VehicleData $data, OwnershipFiles $files = new OwnershipFiles()): Vehicle
    {
        if ($data->type !== $vehicle->data->type) {
            $positions = $data->type->tyrePositions();
            foreach ($this->tyres->listTyres($vehicle->id) as $tyre) {
                if ($tyre->isFitted() && $tyre->position !== null && !in_array($tyre->position, $positions, true)) {
                    throw new TyresBlockTypeChange($data->type, $tyre->position);
                }
            }
        }
        $refusal = PaperworkNeedsDate::check(
            $data,
            count($this->attachments->forOwner($vehicle, AttachmentOwner::Purchase, $vehicle->id)),
            count($files->purchase),
            count($this->attachments->forOwner($vehicle, AttachmentOwner::Sale, $vehicle->id)),
            count($files->sale),
        );
        if ($refusal !== null) {
            throw $refusal;
        }

        $this->attachments->saveWithFiles($files->all(), function (array $stored) use ($user, $vehicle, $data, $files): void {
            $this->vehicles->update($user->id, $vehicle->id, $data, $this->clock->now());
            $this->recordPaperwork($vehicle, $files, $stored);
        });

        return $this->get($user, $vehicle->id);
    }

    /**
     * Delete the vehicle with its history, photo and attachments.
     */
    public function delete(User $user, Vehicle $vehicle): void
    {
        $this->attachments->deleteFilesForVehicle($vehicle);
        $this->vehicles->delete($user->id, $vehicle->id);
        $this->files->delete($vehicle->photoPath);
    }

    public function archive(User $user, Vehicle $vehicle): void
    {
        $this->vehicles->setStatus($user->id, $vehicle->id, VehicleStatus::Archived, $this->clock->now());
    }

    public function restore(User $user, Vehicle $vehicle): void
    {
        $this->vehicles->setStatus($user->id, $vehicle->id, VehicleStatus::Active, $this->clock->now());
    }

    /**
     * Store a new photo (already checked with FileUpload) and delete the old one.
     */
    public function replacePhoto(User $user, Vehicle $vehicle, UploadedFileInterface $file, FileUpload $checked): void
    {
        if (!$checked->isValid() || $checked->extension === null || $checked->mime === null) {
            throw new InvalidArgumentException('Only a validated image can be stored.');
        }

        $path = $this->files->store($file, self::PHOTO_DIRECTORY, $checked->extension);
        $this->vehicles->setPhoto($user->id, $vehicle->id, $path, $checked->mime, $this->clock->now());
        $this->files->delete($vehicle->photoPath);
    }

    public function removePhoto(User $user, Vehicle $vehicle): void
    {
        $this->vehicles->setPhoto($user->id, $vehicle->id, null, null, $this->clock->now());
        $this->files->delete($vehicle->photoPath);
    }

    /**
     * Absolute path of the vehicle's photo file, or null when there is none
     * (or it has gone missing from disk).
     */
    public function photoFile(Vehicle $vehicle): ?string
    {
        if ($vehicle->photoPath === null || !$this->files->exists($vehicle->photoPath)) {
            return null;
        }

        return $this->files->absolutePath($vehicle->photoPath);
    }

    /**
     * Insert the rows of the paperwork saveWithFiles() wrote: the purchase
     * files first, in the order OwnershipFiles::all() gave them.
     *
     * @param list<StoredFile> $stored
     */
    private function recordPaperwork(Vehicle $vehicle, OwnershipFiles $files, array $stored): void
    {
        $purchase = count($files->purchase);
        $this->attachments->record($vehicle, AttachmentOwner::Purchase, $vehicle->id, array_slice($stored, 0, $purchase));
        $this->attachments->record($vehicle, AttachmentOwner::Sale, $vehicle->id, array_slice($stored, $purchase));
    }

    /**
     * Currency for this vehicle's amounts: its override, else the owner's
     * default, else APP_CURRENCY.
     */
    public function currencyFor(User $user, Vehicle $vehicle): string
    {
        return Currency::resolve($vehicle->data->currency, $user->preferences->currency, $this->settings->currency);
    }

    public function maxPhotoMegabytes(): int
    {
        return $this->settings->maxUploadMb;
    }

    public function maxPhotoBytes(): int
    {
        return $this->settings->maxUploadMb * 1024 * 1024;
    }
}
