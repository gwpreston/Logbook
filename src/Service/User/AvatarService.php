<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Support\Storage\FileStorage;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\ImageCleaner;
use Logbook\Support\Storage\UploadKind;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Avatars (spec.md §7.9 *Avatars*): JPEG, PNG or WebP up to 5 MB, judged by
 * content, turned upright and re-encoded to a 256 × 256 centre-cropped
 * WebP (JPEG without WebP), so no metadata survives. The original is not
 * kept. Stored under UPLOAD_PATH/avatars, outside the web root.
 */
final readonly class AvatarService
{
    public const int MAX_MB = 5;
    public const int EDGE = 256;
    public const string DIRECTORY = 'avatars';

    public function __construct(
        private UserRepository $users,
        private FileStorage $files,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Replace the user's avatar with this upload: null when it is stored,
     * otherwise the translation key of what is wrong with it.
     */
    public function upload(User $user, UploadedFileInterface $file): ?string
    {
        $check = FileUpload::check($file, self::MAX_MB * 1024 * 1024, UploadKind::Image);
        if (!$check->isValid() || $check->mime === null) {
            return $check->error ?? 'upload.not_an_image';
        }
        $path = $file->getStream()->getMetadata('uri');
        $square = is_string($path) ? ImageCleaner::square($path, $check->mime, self::EDGE) : null;
        if ($square === null) {
            return 'upload.not_an_image';
        }

        $relative = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.' . $square['extension'];
        $absolute = $this->files->absolutePath($relative);
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create upload directory "%s".', $directory));
        }
        if (file_put_contents($absolute, $square['bytes']) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $relative));
        }

        $this->users->setAvatar($user->id, $relative, $this->clock->now());
        $this->deleteFile($user->avatarPath);

        return null;
    }

    public function remove(User $user): void
    {
        if ($user->avatarPath === null) {
            return;
        }
        $this->users->setAvatar($user->id, null, $this->clock->now());
        $this->deleteFile($user->avatarPath);
    }

    /**
     * The stored file of a user's avatar, if it is there.
     *
     * @return array{path: string, mime: string}|null
     */
    public function file(User $user): ?array
    {
        if ($user->avatarPath === null || !FileStorage::isStoredPath($user->avatarPath)) {
            return null;
        }
        $absolute = $this->files->absolutePath($user->avatarPath);
        if (!is_file($absolute)) {
            return null;
        }

        return [
            'path' => $absolute,
            'mime' => str_ends_with($user->avatarPath, '.webp') ? 'image/webp' : 'image/jpeg',
        ];
    }

    /**
     * Delete the file of an avatar no longer used (a replaced one, or a
     * deleted user's).
     */
    public function deleteFile(?string $relative): void
    {
        if ($relative !== null && FileStorage::isStoredPath($relative)) {
            $this->files->delete($relative);
        }
    }
}
