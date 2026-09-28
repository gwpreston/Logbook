<?php

declare(strict_types=1);

namespace Logbook\Service\Attachment;

/**
 * A file written under UPLOAD_PATH whose attachment row is not inserted yet
 * (AttachmentService::withFiles()).
 */
final readonly class StoredFile
{
    public function __construct(
        /** Relative to UPLOAD_PATH. */
        public string $path,
        /** The sanitised display name. */
        public string $name,
        public string $mime,
        public int $size,
    ) {
    }
}
