<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use InvalidArgumentException;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Receipts, invoices and certificates attached to fill-ups, maintenance
 * entries and compliance documents (spec.md §7.12).
 *
 * Uploads take the same path as vehicle photos: checked by FileUpload
 * (content type, MAX_UPLOAD_MB), stored by FileStorage under UPLOAD_PATH
 * with a random name, and served only by an authenticated Action through
 * Support\Http\FileResponder.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner, and an
 * owner entry already resolved on that vehicle.
 */
final readonly class AttachmentService
{
    private const string DIRECTORY = 'attachments';
    private const int MAX_NAME_LENGTH = 150;

    public function __construct(
        private AttachmentRepository $attachments,
        private FileStorage $files,
        private ClockInterface $clock,
        private AppSettings $settings,
    ) {
    }

    /**
     * Every attachment of the vehicle, grouped by the entry it belongs to.
     */
    public function index(Vehicle $vehicle): AttachmentIndex
    {
        return new AttachmentIndex($this->attachments->listForVehicle($vehicle->id));
    }

    /**
     * @return list<Attachment>
     */
    public function forOwner(Vehicle $vehicle, AttachmentOwner $type, int $ownerId): array
    {
        return $this->attachments->listForOwner($vehicle->id, $type, $ownerId);
    }

    /**
     * @throws AttachmentNotFound
     */
    public function get(Vehicle $vehicle, int $id): Attachment
    {
        return $this->attachments->find($vehicle->id, $id)
            ?? throw new AttachmentNotFound(sprintf('Attachment %d not found.', $id));
    }

    public function check(UploadedFileInterface $file): FileUpload
    {
        return FileUpload::check($file, $this->maxBytes(), UploadKind::Document);
    }

    /**
     * Store a file (already checked with check()) against an entry.
     */
    public function attach(
        Vehicle $vehicle,
        AttachmentOwner $type,
        int $ownerId,
        UploadedFileInterface $file,
        FileUpload $checked,
    ): Attachment {
        if (!$checked->isValid() || $checked->mime === null || $checked->extension === null) {
            throw new InvalidArgumentException('Only a validated file can be stored.');
        }

        $size = (int) $file->getSize();
        $name = self::displayName($file->getClientFilename(), $checked->extension);
        $path = $this->files->store($file, self::DIRECTORY, $checked->extension);
        $id = $this->attachments->insert($vehicle->id, $type, $ownerId, $name, $checked->mime, $size, $path, $this->clock->now());

        return $this->get($vehicle, $id);
    }

    public function delete(Vehicle $vehicle, Attachment $attachment): void
    {
        $this->attachments->delete($vehicle->id, $attachment->id);
        $this->files->delete($attachment->storedPath);
    }

    /**
     * Remove everything attached to an entry that is being deleted.
     */
    public function deleteForOwner(Vehicle $vehicle, AttachmentOwner $type, int $ownerId): void
    {
        foreach ($this->attachments->listForOwner($vehicle->id, $type, $ownerId) as $attachment) {
            $this->delete($vehicle, $attachment);
        }
    }

    /**
     * Delete the files of a vehicle that is being deleted (its rows go with
     * it by foreign key).
     */
    public function deleteFilesForVehicle(Vehicle $vehicle): void
    {
        foreach ($this->attachments->listForVehicle($vehicle->id) as $attachment) {
            $this->files->delete($attachment->storedPath);
        }
    }

    /**
     * Absolute path of the stored file, or null if it has gone missing.
     */
    public function file(Attachment $attachment): ?string
    {
        return $this->files->exists($attachment->storedPath) ? $this->files->absolutePath($attachment->storedPath) : null;
    }

    public function maxMegabytes(): int
    {
        return $this->settings->maxUploadMb;
    }

    private function maxBytes(): int
    {
        return $this->settings->maxUploadMb * 1024 * 1024;
    }

    /**
     * The uploaded name, reduced to something safe to show and to offer as a
     * download name: no directories or control characters, a sane length,
     * and the extension that matches the detected type.
     */
    private static function displayName(?string $clientName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', (string) $clientName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_check_encoding($name, 'UTF-8') ? $name : '');
        $stem = trim((string) preg_replace('/\.[^.]*$/', '', $name), " .\t");
        if ($stem === '') {
            $stem = 'attachment';
        }

        return mb_substr($stem, 0, self::MAX_NAME_LENGTH) . '.' . $extension;
    }
}
