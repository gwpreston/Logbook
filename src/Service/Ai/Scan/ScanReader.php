<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanStatus;
use Logbook\Domain\Ai\Scan\ScanTarget;
use Logbook\Domain\Ai\Scan\ScanUpload;
use Logbook\Domain\User\User;
use Logbook\Repository\PendingUploadRepository;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * A scan from upload to stored reading (spec.md §7.27). The checked (and,
 * for a photo, stripped) file is kept as a pending upload first, marked
 * `reading`, so it survives whatever the model does; then it is prepared,
 * read, and the extraction or the reason it could not be read is stored.
 */
final readonly class ScanReader
{
    private const int MAX_NAME_LENGTH = 150;

    public function __construct(
        private PendingUploadRepository $uploads,
        private FileStorage $files,
        private FilePreparer $preparer,
        private Extractor $extractor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Keep the file (already checked by FileUpload) as a pending upload.
     */
    public function keep(
        User $user,
        UploadedFileInterface $file,
        FileUpload $checked,
        ?int $vehicleId,
        ?ScanTarget $target,
    ): ScanUpload {
        if (!$checked->isValid() || $checked->mime === null || $checked->extension === null) {
            throw new \InvalidArgumentException('Only a validated file can be kept.');
        }
        $path = $this->files->store($file, FileStorage::PENDING_DIRECTORY, $checked->extension);
        try {
            return $this->uploads->insert(
                $user->id,
                bin2hex(random_bytes(16)),
                self::displayName($file->getClientFilename(), $checked->extension),
                $checked->mime,
                (int) ($checked->size ?? $file->getSize()),
                $path,
                $vehicleId,
                $target,
                $this->clock->now(),
            );
        } catch (Throwable $e) {
            $this->files->delete($path);
            throw $e;
        }
    }

    /**
     * Read a kept file and store what was found (or why not). Returns the
     * row as it is now.
     */
    public function read(User $user, ScanUpload $upload): ScanUpload
    {
        if ($upload->status !== ScanStatus::Reading || $upload->storedPath === null) {
            return $upload;
        }
        try {
            $prepared = $this->preparer->prepare($this->files->absolutePath($upload->storedPath), $upload->mime);
            $read = $this->extractor->extract($user, $prepared);
        } catch (Throwable) {
            $prepared = null;
            $read = ScanProblem::Failed;
        }

        if ($read instanceof Extraction) {
            $this->uploads->setResult($upload->id, ScanStatus::Read, $read->toArray(), $prepared?->pageCount);
        } else {
            $this->uploads->setResult($upload->id, ScanStatus::Failed, ['error' => $read->value], $prepared?->pageCount);
        }

        return $this->uploads->find($user->id, $upload->token) ?? $upload;
    }

    public function find(User $user, string $token): ?ScanUpload
    {
        return $this->uploads->find($user->id, $token);
    }

    /**
     * The extraction stored on a read upload; null for one that failed or is still reading.
     */
    public static function extraction(ScanUpload $upload): ?Extraction
    {
        return Extraction::fromStored($upload->result);
    }

    public static function problem(ScanUpload $upload): ?ScanProblem
    {
        $error = $upload->result['error'] ?? null;

        return is_string($error) ? (ScanProblem::tryFrom($error) ?? ScanProblem::Failed) : null;
    }

    /**
     * The file's absolute path while it is pending, else null.
     */
    public function path(ScanUpload $upload): ?string
    {
        return $upload->storedPath !== null && $this->files->exists($upload->storedPath)
            ? $this->files->absolutePath($upload->storedPath)
            : null;
    }

    private static function displayName(?string $clientName, string $extension): string
    {
        $name = basename(str_replace('\\', '/', (string) $clientName));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', mb_check_encoding($name, 'UTF-8') ? $name : '');
        $stem = trim((string) preg_replace('/\.[^.]*$/', '', $name), " .\t");

        return mb_substr($stem === '' ? 'scan' : $stem, 0, self::MAX_NAME_LENGTH) . '.' . $extension;
    }
}
