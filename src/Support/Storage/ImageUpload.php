<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

use finfo;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Checks an uploaded image by its content (never by its name or the
 * browser-supplied type): JPEG, PNG or WebP, within the size limit, and
 * actually decodable as an image of sane dimensions.
 */
final readonly class ImageUpload
{
    /** MIME type → stored file extension. */
    public const array TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_DIMENSION = 20000;

    private function __construct(
        public ?string $mime,
        public ?string $extension,
        /** Translation key of the problem, or null when the image is acceptable. */
        public ?string $error,
    ) {
    }

    /**
     * Whether a file was actually chosen (an empty file input submits
     * UPLOAD_ERR_NO_FILE).
     */
    public static function wasProvided(?UploadedFileInterface $file): bool
    {
        return $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
    }

    public static function check(UploadedFileInterface $file, int $maxBytes): self
    {
        $error = match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'upload.too_large',
            UPLOAD_ERR_PARTIAL => 'upload.partial',
            default => 'upload.failed',
        };
        if ($error !== null) {
            return self::invalid($error);
        }

        $size = $file->getSize();
        if ($size === null || $size === 0) {
            return self::invalid('upload.empty');
        }
        if ($size > $maxBytes) {
            return self::invalid('upload.too_large');
        }

        $path = $file->getStream()->getMetadata('uri');
        if (!is_string($path) || !is_file($path)) {
            return self::invalid('upload.failed');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            return self::invalid('upload.not_an_image');
        }

        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            return self::invalid('upload.not_an_image');
        }

        return new self($mime, self::TYPES[$mime], null);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    private static function invalid(string $error): self
    {
        return new self(null, null, $error);
    }
}
