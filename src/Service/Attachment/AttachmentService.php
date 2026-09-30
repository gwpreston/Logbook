<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

use Logbook\Service\Access\AccessContext;
use Closure;
use InvalidArgumentException;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Attachment\AttachmentOwner;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AttachmentRepository;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Database\Transaction;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Receipts, invoices and certificates attached to fill-ups, maintenance
 * entries, compliance documents, expenses and manual odometer readings
 * (spec.md §7.12).
 *
 * Uploads take the same path as vehicle photos: checked by FileUpload
 * (content type, MAX_UPLOAD_MB), stored by FileStorage under UPLOAD_PATH
 * with a random name, and served only by an authenticated Action through
 * Support\Http\FileResponder.
 *
 * Several files can be saved at once, all or nothing: the entry's service
 * saves the entry inside saveWithFiles(), which writes the checked files
 * first, runs the save in one transaction (where the service calls
 * record()), and deletes the files again if that transaction fails.
 *
 * Callers pass a Vehicle already resolved for the signed-in owner, and an
 * owner entry already resolved on that vehicle.
 */
final readonly class AttachmentService
{
    /** Files per save, unless PHP's max_file_uploads is lower. */
    public const int MAX_FILES = 10;

    private const string DIRECTORY = 'attachments';
    private const int MAX_NAME_LENGTH = 150;

    public function __construct(
        private AttachmentRepository $attachments,
        private FileStorage $files,
        private ClockInterface $clock,
        private AppSettings $settings,
        private Transaction $transaction,
        /** PHP's max_file_uploads: files past it are dropped silently. */
        private int $phpMaxFileUploads,
        private AccessContext $author,
    ) {
    }

    /**
     * How many files one save takes: MAX_FILES, or PHP's max_file_uploads
     * when that is lower (PHP drops the rest without an error, so the app's
     * limit must never sit above it).
     */
    public static function fileLimit(int $phpMaxFileUploads): int
    {
        return max(0, min(self::MAX_FILES, $phpMaxFileUploads));
    }

    public function maxFiles(): int
    {
        return self::fileLimit($this->phpMaxFileUploads);
    }

    /**
     * Number of files per entry, for paperclips (one grouped query).
     */
    public function counts(Vehicle $vehicle): AttachmentCounts
    {
        return new AttachmentCounts($this->attachments->countByOwner([$vehicle->id]));
    }

    /**
     * Number of files of just these entries (the items of one page).
     *
     * @param list<int> $vehicleIds vehicles already resolved for the owner
     * @param array<string, list<int>> $owners owner type → entry ids
     */
    public function countsFor(array $vehicleIds, array $owners): AttachmentCounts
    {
        return new AttachmentCounts($this->attachments->countByOwner($vehicleIds, $owners));
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
     * Write the files (every one already checked), then run $save in one
     * transaction; $save saves the entry and calls record() with the files.
     * If anything fails, the files just written are deleted again.
     *
     * @template T
     * @param Closure(list<StoredFile>): T $save
     * @return T
     */
    public function saveWithFiles(PendingUploads $uploads, Closure $save): mixed
    {
        $stored = [];
        try {
            foreach ($uploads->files as $upload) {
                $stored[] = $this->write($upload);
            }

            return $this->transaction->run(static fn (): mixed => $save($stored));
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->files->delete($file->path);
            }
            throw $e;
        }
    }

    /**
     * Insert the rows of the files saveWithFiles() wrote, against the entry
     * just saved.
     *
     * @param list<StoredFile> $stored
     */
    public function record(Vehicle $vehicle, AttachmentOwner $type, int $ownerId, array $stored): void
    {
        $now = $this->clock->now();
        foreach ($stored as $file) {
            $this->attachments->insert($vehicle->id, $type, $ownerId, $file->name, $file->mime, $file->size, $file->path, $now, $this->author->authorId());
        }
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

    private function write(PendingUpload $upload): StoredFile
    {
        $checked = $upload->check;
        if (!$checked->isValid() || $checked->mime === null || $checked->extension === null) {
            throw new InvalidArgumentException('Only a validated file can be stored.');
        }

        return new StoredFile(
            $this->files->store($upload->file, self::DIRECTORY, $checked->extension),
            self::displayName($upload->file->getClientFilename(), $checked->extension),
            $checked->mime,
            (int) $upload->file->getSize(),
        );
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
