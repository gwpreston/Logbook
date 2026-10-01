<?php

declare(strict_types=1);

namespace Logbook\Support\Storage;

use finfo;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The single check every upload goes through (vehicle photos and
 * attachments alike). A file is judged by its content — never by its name
 * or the browser-supplied type — against the kind's accepted types and the
 * size limit (MAX_UPLOAD_MB). Images must also decode to sane dimensions;
 * PDFs must start with a PDF header.
 *
 * An accepted image is also cleaned in place (spec.md §7.12): turned
 * upright and re-encoded without its metadata (EXIF, GPS included), so
 * whatever stores or reads it afterwards only ever sees the clean file.
 */
final readonly class FileUpload
{
    private const int MAX_DIMENSION = 20000;

    private function __construct(
        public ?string $mime,
        public ?string $extension,
        /** Bytes of the accepted file (after cleaning an image). */
        public ?int $size,
        /** Translation key of the problem, or null when the file is acceptable. */
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

    public static function check(UploadedFileInterface $file, int $maxBytes, UploadKind $kind): self
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

        $types = $kind->types();
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!is_string($mime) || !isset($types[$mime]) || !self::contentMatches($path, $mime)) {
            return self::invalid($kind->typeError());
        }
        if ($mime !== 'application/pdf') {
            $decodes = $kind->keepsMetadata() ? ImageCleaner::decodes($path, $mime) : ImageCleaner::clean($path, $mime);
            if (!$decodes) {
                return self::invalid($kind->typeError());
            }
        }
        $stored = filesize($path);

        return new self($mime, $types[$mime], $stored === false ? $size : $stored, null);
    }

    /**
     * A file this check accepted before (a pending scan, spec.md §7.27),
     * now being attached: it is not decoded and re-encoded a second time.
     */
    public static function accepted(string $mime, string $extension, int $size): self
    {
        return new self($mime, $extension, $size, null);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    private static function contentMatches(string $path, string $mime): bool
    {
        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            $header = $handle === false ? false : fread($handle, 5);
            if ($handle !== false) {
                fclose($handle);
            }

            return $header === '%PDF-';
        }

        $info = @getimagesize($path);

        return $info !== false
            && $info[0] >= 1 && $info[1] >= 1
            && $info[0] <= self::MAX_DIMENSION && $info[1] <= self::MAX_DIMENSION;
    }

    private static function invalid(string $error): self
    {
        return new self(null, null, null, $error);
    }
}
