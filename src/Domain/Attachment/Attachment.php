<?php

declare(strict_types=1);

namespace Logbook\Domain\Attachment;

use DateTimeImmutable;

/**
 * A receipt, invoice or certificate attached to an entry (spec.md §6
 * Attachment). The file lives under UPLOAD_PATH with a random name; the
 * original file name is kept for display and downloads only.
 */
final readonly class Attachment
{
    public function __construct(
        public int $id,
        public int $vehicleId,
        public AttachmentOwner $ownerType,
        public int $ownerId,
        /** Original name, sanitised; never used on disk. */
        public string $filename,
        /** Detected from the content at upload. */
        public string $mime,
        public int $size,
        /** Relative to UPLOAD_PATH. */
        public string $storedPath,
        public DateTimeImmutable $uploadedAt,
    ) {
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Icon name in the vendored sprite (assets/vendor/icons.svg).
     */
    public function icon(): string
    {
        return $this->isImage() ? 'image' : ($this->mime === 'application/pdf' ? 'picture_as_pdf' : 'attach_file');
    }

    /**
     * Cache validator: the stored name is random and never reused.
     */
    public function version(): string
    {
        return substr(hash('sha256', $this->storedPath), 0, 12);
    }
}
